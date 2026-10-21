<?php

use App\Models\ApprovalRequest;
use App\Services\DeadlineNotifier;
use App\Services\RequestEffects;
use App\Services\TelegramNotificationMessage;
use App\Services\TrancheWorkflow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;

beforeEach(function () {
    seedRoles();
    fakeBot();
    Storage::fake('local');
    config(['google_sheets.spreadsheet_id' => null]);
    $this->author = makeUserWithRole('requester');
    $this->request = ApprovalRequest::create([
        'applicant_id' => $this->author->id, 'project_id' => makeProject('TEG')->id,
        'type' => 'approval', 'status' => 'approved', 'name' => 'Оборудование <TEG> & кабели',
        'total_price' => 150, 'currency' => 'UZS', 'transaction_type' => 'bank',
    ]);
});

function fakeNotificationProtocol(): void
{
    test()->mock(App\Services\PdfGeneratorService::class)->shouldReceive('generateProtocol')->once()->andReturnUsing(function ($request) {
        Storage::disk('local')->put('protocols/'.$request->status.'.pdf', 'pdf');
        $request->update(['pdf_url' => 'protocols/'.$request->status.'.pdf']);

        return $request->pdf_url;
    });
}

test('delayed final approval and full payment send the paid protocol once in either order', function (bool $paidFirst) {
    app(RequestEffects::class)->record($this->request, 'approved:99');
    $approvalEffect = DB::table('request_effects')->value('id');
    $tranche = $this->request->tranches()->create(['amount' => 150, 'status' => 'pending']);
    app(TrancheWorkflow::class)->update(makeUserWithRole('admin'), $this->request->id, $tranche->id, ['status' => 'paid']);
    $paidEffect = DB::table('request_effects')->where('event', 'paid')->value('id');
    fakeNotificationProtocol();
    $captions = [];
    app(Nutgram::class)->shouldReceive('sendDocument')->once()->andReturnUsing(function (...$args) use (&$captions) {
        $captions[] = $args[4];
    });
    $order = $paidFirst ? [$paidEffect, $approvalEffect] : [$approvalEffect, $paidEffect];
    foreach (array_merge($order, $order) as $id) {
        app(RequestEffects::class)->deliver($id);
    }
    expect($captions)->toBe(["📄 Протокол #{$this->request->id}\n✅ Заявка полностью оплачена"])
        ->and(DB::table('request_effects')->whereIn('id', $order)->whereNull('completed_at')->count())->toBe(0);
})->with([false, true]);

test('approval and later payment still deliver their distinct protocol versions', function () {
    $paths = [];
    $this->mock(App\Services\PdfGeneratorService::class)->shouldReceive('generateProtocol')->twice()->andReturnUsing(function ($request) use (&$paths) {
        $path = 'protocols/'.$request->status.'.pdf';
        $paths[] = $path;
        Storage::disk('local')->put($path, $request->status);
        $request->update(['pdf_url' => $path]);

        return $path;
    });
    app(Nutgram::class)->shouldReceive('sendDocument')->twice();
    app(RequestEffects::class)->record($this->request, 'approved:99');
    app(RequestEffects::class)->deliver(DB::table('request_effects')->value('id'));
    $tranche = $this->request->tranches()->create(['amount' => 150, 'status' => 'pending']);
    app(TrancheWorkflow::class)->update(makeUserWithRole('admin'), $this->request->id, $tranche->id, ['status' => 'paid']);
    app(RequestEffects::class)->deliver(DB::table('request_effects')->where('event', 'paid')->value('id'));
    expect($paths)->toBe(['protocols/approved.pdf', 'protocols/paid.pdf']);
});

test('a failed paid document retries without regenerating the PDF or resending the message', function () {
    $this->request->update(['status' => 'paid']);
    app(RequestEffects::class)->record($this->request, 'paid');
    fakeNotificationProtocol();
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once();
    $attempt = 0;
    $bot->shouldReceive('sendDocument')->twice()->andReturnUsing(function () use (&$attempt) {
        if (++$attempt === 1) {
            throw new RuntimeException('Temporary transport error');
        }
    });
    app()->instance(Nutgram::class, $bot);
    $id = DB::table('request_effects')->value('id');
    expect(fn () => app(RequestEffects::class)->deliver($id))->toThrow(RuntimeException::class);
    app(RequestEffects::class)->deliver($id);
    app(RequestEffects::class)->deliver($id);
    expect(DB::table('request_effects')->value('completed_at'))->not->toBeNull();
});

test('different events of the same request cannot deliver concurrently', function () {
    app(RequestEffects::class)->record($this->request, 'cancelled');
    $id = DB::table('request_effects')->value('id');
    $lock = Cache::lock('request-effects:'.$this->request->id, 600);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => app(RequestEffects::class)->deliver($id))->toThrow(Illuminate\Contracts\Cache\LockTimeoutException::class);
        expect(DB::table('request_effects')->value('completed_at'))->toBeNull();
    } finally {
        $lock->release();
    }
});

test('long HTML-like content and emoji remain intact across independently valid pages', function () {
    $text = "🔔 Заголовок\n\n0\n📋 Заявка: ".str_repeat('<b> & " :name 😀 ', 400)."\nhttps://example.test/request";
    $pages = app(TelegramNotificationMessage::class)->pages($text);
    $visible = '';
    expect(count($pages))->toBeGreaterThan(1);
    foreach ($pages as $page) {
        expect(mb_strlen($page))->toBeLessThanOrEqual(2000);
        $dom = new DOMDocument;
        expect($dom->loadXML('<root>'.$page.'</root>'))->toBeTrue();
        $visible .= $dom->documentElement->textContent;
    }
    expect($visible)->toBe($text)->and(implode('', $pages))->not->toContain('<b><b>', '<b>https:');
});

test('payment cards show readable status amount date and proof with HTML escaping', function () {
    $messages = [];
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->andReturnUsing(function (...$args) use (&$messages) {
        $messages[] = $args;
    });
    app()->instance(Nutgram::class, $bot);
    app(RequestEffects::class)->record($this->request, 'tranche:2492:test', [
        'tranche_id' => 2492, 'tranche_amount' => 150, 'tranche_status' => 'paid',
        'promised_date' => '2026-10-14', 'proof' => true, 'reason' => '<i>готово</i> & проверено',
    ]);
    app(RequestEffects::class)->deliver(DB::table('request_effects')->value('id'));
    expect($messages[0][0])->toContain('<b>💳 Обновлён платёж', '✅ Оплачен', '150.00 UZS', '14.10.2026', '&lt;TEG&gt;', '&lt;i&gt;готово&lt;/i&gt;', route('requests.proof', [$this->request->id, 2492]))
        ->not->toContain(': paid', '<i>готово</i>');
    expect($messages[0][3])->toBe('HTML');
});

test('English recipients receive localized event cards and buttons', function () {
    $this->author->update(['lang' => 'en']);
    $messages = [];
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function (...$args) use (&$messages) {
        $messages[] = $args;
    });
    app()->instance(Nutgram::class, $bot);
    app(RequestEffects::class)->record($this->request, 'revision_requested', ['reason' => 'Check totals']);
    app(RequestEffects::class)->deliver(DB::table('request_effects')->value('id'));
    expect($messages[0][0])->toContain('Request returned for revision', '📁 Project:', 'Check totals')->not->toContain('Заявка');
    $keyboard = json_decode(json_encode($messages[0][12]), true);
    expect($keyboard['inline_keyboard'][0][0]['text'])->toBe('Open request on the website');
});

test('deadline and tracker templates escape raw user values once and preserve parameter-like content', function () {
    $messages = [];
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function (...$args) use (&$messages) {
        $messages[] = $args;
    });
    app()->instance(Nutgram::class, $bot);
    app(DeadlineNotifier::class)->send($this->author, 'bot.dl_stuck_approver', [
        'id' => $this->request->id, 'name' => '<a> & :hours', 'hours' => 5,
    ]);
    app(DeadlineNotifier::class)->send($this->author, 'bot.tracker_moved', ['title' => '<b> :list', 'list' => 'Done & ready']);
    expect($messages[0][0])->toContain('&lt;a&gt; &amp; :hours', '5 ч.')->not->toContain('&amp;lt;');
    expect($messages[1][0])->toContain('&lt;b&gt; :list', 'Done &amp; ready');
    expect(json_decode(json_encode($messages[0][12]), true)['inline_keyboard'][0][0]['url'])->toBe(route('requests.detail', $this->request->id));
});
