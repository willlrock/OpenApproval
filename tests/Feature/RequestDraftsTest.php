<?php

use App\Models\ApprovalRequest;
use App\Models\RequestDraft;
use App\Services\ApprovalRequestCreation;
use App\Services\ApprovalWorkflow;
use App\Services\RequestCreationNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    $this->bot = fakeBot();
    Storage::fake('local');
    $this->author = makeUserWithRole('requester');
    $this->project = makeProject('Web project');
    $this->author->projects()->attach($this->project);
    $this->approver = makeUserWithRole('approver');
    $this->approver->projects()->attach($this->project);
    $this->actingAs($this->author);
});
function webPayload(array $overrides = []): array
{
    return array_merge(['type' => 'approval', 'project_id' => test()->project->id, 'currency' => 'UZS', 'transaction_type' => 'bank',
        'supplier_name' => 'Поставщик', 'supplier_inn' => '123456789', 'deadline_date' => today()->addDays(3)->toDateString(),
        'items' => [['name' => 'Кабель', 'description' => '1.5 метра', 'quantity' => '1.5', 'price_per_unit' => '10.00', 'total_price' => 999999]],
        'approvers' => [test()->approver->id], 'tranches' => []], $overrides);
}
function webDraft(array $payload = []): RequestDraft
{
    return RequestDraft::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => test()->author->id, 'payload' => $payload ?: webPayload()]);
}

test('requesters can use the dashboard and workspace without seeing other peoples requests', function () {
    $other = makeUserWithRole('requester');
    $own = ApprovalRequest::create(['type' => 'approval', 'project_id' => $this->project->id,
        'applicant_id' => $this->author->id, 'name' => 'My request', 'status' => 'pending_approval']);
    $hidden = ApprovalRequest::create(['type' => 'approval', 'project_id' => $this->project->id,
        'applicant_id' => $other->id, 'name' => 'Another request', 'status' => 'pending_approval']);

    $this->get(route('request-drafts.index'))->assertOk();
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('stats.totalRequests', 1));
    $this->get(route('requests'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('requests.data', 1)->where('requests.data.0.id', $own->id));
    $this->get(route('requests.detail', $hidden->id))->assertNotFound();
    $this->get(route('finance.requests'))->assertForbidden();
    $this->get(route('users'))->assertForbidden();
    $this->post(route('request-drafts.store'))->assertRedirect();
    $draft = RequestDraft::first();
    $this->get(route('request-drafts.edit', $draft->id))->assertOk();
    expect(ApprovalRequest::count())->toBe(2);
});

test('partial invalid inputs are saved as a draft and errors do not destroy it', function () {
    $draft = webDraft();
    $partial = ['type' => 'approval', 'items' => [['name' => 'Не закончено', 'quantity' => '']], 'approvers' => [], 'tranches' => []];
    $this->putJson(route('request-drafts.update', $draft->id), ['version' => 1, 'payload' => $partial])->assertOk()->assertJsonPath('version', 2);
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 2])->assertUnprocessable();
    expect($draft->fresh()->payload['items'][0]['name'])->toBe('Не закончено')->and(ApprovalRequest::count())->toBe(0);
});

test('stale saves and stale submissions cannot overwrite a newer draft', function () {
    $draft = webDraft();
    $this->putJson(route('request-drafts.update', $draft->id), ['version' => 1, 'payload' => webPayload(['supplier_name' => 'New'])])->assertOk();
    $this->putJson(route('request-drafts.update', $draft->id), ['version' => 1, 'payload' => webPayload(['supplier_name' => 'Old'])])->assertConflict();
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertConflict();
    expect($draft->fresh()->payload['supplier_name'])->toBe('New')->and(ApprovalRequest::count())->toBe(0);
});

test('zero and negative quantities cannot be submitted or silently replaced', function (string $quantity) {
    $payload = webPayload();
    $payload['items'][0]['quantity'] = $quantity;
    $draft = webDraft($payload);

    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

    expect($draft->fresh()->payload['items'][0]['quantity'])->toBe($quantity)
        ->and(ApprovalRequest::count())->toBe(0);
})->with(['0', '-1', '-0.5']);

test('submission is idempotent and calculates totals on the server', function () {
    $draft = webDraft();
    $first = $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertOk();
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertOk()->assertJsonPath('url', $first->json('url'));
    $request = ApprovalRequest::first();
    expect(ApprovalRequest::count())->toBe(1)->and((float) $request->total_price)->toBe(15.0)
        ->and((float) $request->items->first()->total_price)->toBe(15.0)->and((float) $request->items->first()->quantity)->toBe(1.5)
        ->and($request->tranches->first()->due_date->toDateString())->toBe(today()->addDays(3)->toDateString());
    $this->putJson(route('request-drafts.update', $draft->id), ['version' => 2, 'payload' => webPayload()])->assertConflict();
    $this->get($first->json('url'))->assertOk();
});

test('an omitted expense deadline defaults to the submission date in Tashkent', function (array $deadline) {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-12 12:00:00', 'Asia/Tashkent'));
    $payload = webPayload();
    unset($payload['deadline_date']);
    $draft = webDraft(array_merge($payload, $deadline));
    $this->travelTo(\Carbon\Carbon::parse('2026-10-12 20:30:00', 'UTC'));

    $first = $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertOk();
    $request = ApprovalRequest::first();
    expect($request->deadline_date->toDateString())->toBe('2026-10-13')
        ->and($request->tranches->first()->due_date->toDateString())->toBe('2026-10-13');

    $this->get($first->json('url'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('request.deadline_date', '13.10.2026'));
    expect(view('pdf.request', ['request' => $request->load('project', 'items', 'approvals.approver', 'tranches')])->render())
        ->toContain('<td><b>13.10.2026</b></td>');

    $this->travelTo(\Carbon\Carbon::parse('2026-10-14 12:00:00', 'Asia/Tashkent'));
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertOk();
    expect($request->fresh()->deadline_date->toDateString())->toBe('2026-10-13');
})->with([
    'missing' => [[]],
    'null' => [['deadline_date' => null]],
    'empty' => [['deadline_date' => '']],
    'whitespace' => [['deadline_date' => '   ']],
]);

test('supply still requires an explicit deadline', function () {
    $director = makeUserWithRole('supplier');
    $director->projects()->attach($this->project);
    $draft = webDraft(webPayload(['type' => 'supply', 'deadline_date' => '']));
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('deadline_date');
    expect(ApprovalRequest::count())->toBe(0);
});

test('submission accepts spaces and decimal commas for prices and payments', function (string $price) {
    $payload = webPayload(['items' => [['name' => 'Хостинг', 'quantity' => '1', 'price_per_unit' => $price]]]);
    $amount = str_replace(',', '.', preg_replace('/[\s\p{Z}]+/u', '', $price));
    $payload['tranches'] = [['amount' => $price, 'due_date' => today()->addDay()->toDateString()]];
    $draft = webDraft($payload);
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])
        ->assertOk()->assertJsonPath('url', route('requests.detail', ApprovalRequest::first()->id));
    $request = ApprovalRequest::first();
    expect((float) $request->total_price)->toBe((float) $amount)
        ->and((float) $request->items->first()->price_per_unit)->toBe((float) $amount)
        ->and((float) $request->tranches->first()->amount)->toBe((float) $amount);
})->with(['600 000', '600000 ', "600\u{00A0}000", "600\u{202F}000", ' 600 000,50 ', '600000.50']);

test('formatted prices do not bypass numeric limits or decimal precision', function (string $price) {
    $draft = webDraft(webPayload(['items' => [['name' => 'Хостинг', 'quantity' => '1', 'price_per_unit' => $price]]]));
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertJsonValidationErrors('items.0.price_per_unit');
    expect(ApprovalRequest::count())->toBe(0);
})->with(['-600 000', '1,000.50', '600 000,123', '1 000 000 001', '   ']);

test('old submission links redirect to the shared card and retain owner checks', function () {
    $request = app(ApprovalRequestCreation::class)->create($this->author, webPayload());
    $this->get(route('request-submissions.show', $request->id))->assertRedirect(route('requests.detail', $request->id));
    $this->get(route('requests.detail', $request->id))->assertOk()->assertInertia(fn ($page) => $page
        ->component('Dashboard/RequestDetail')->where('request.can_copy', true)->where('request.can_revise', false)
        ->has('request.tranches', 1)->where('request.tranches.0.status', 'pending'));
    $this->actingAs(makeUserWithRole('requester'));
    $this->get(route('request-submissions.show', $request->id))->assertNotFound();
    $this->get(route('requests.detail', $request->id))->assertNotFound();
    $this->post(route('request-submissions.copy', $request->id))->assertNotFound();
    $this->post(route('request-submissions.revise', $request->id))->assertNotFound();
});

test('shared card offers author revision only for returned unpaid requests', function () {
    $request = app(ApprovalRequestCreation::class)->create($this->author, webPayload());
    $request->update(['status' => 'revision_requested']);
    $this->get(route('requests.detail', $request->id))->assertInertia(fn ($page) => $page
        ->where('request.can_copy', true)->where('request.can_revise', true));
    $this->actingAs($this->approver)->get(route('requests.detail', $request->id))->assertInertia(fn ($page) => $page
        ->where('request.can_copy', false)->where('request.can_revise', false));
    $this->actingAs($this->author);
    $request->tranches()->update(['status' => 'paid', 'paid_at' => now()]);
    $this->get(route('requests.detail', $request->id))->assertInertia(fn ($page) => $page
        ->where('request.can_revise', false)->where('request.tranches.0.status', 'paid'));
});

test('draft and private file access is limited to the author even for administrators', function () {
    $draft = webDraft();
    $upload = $this->postJson(route('request-drafts.upload', $draft->id), ['version' => 1, 'file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')])->assertOk();
    $fileId = $upload->json('files.0.id');
    $this->get(route('request-drafts.file', [$draft->id, $fileId]))->assertDownload();
    $this->actingAs(makeUserWithRole('admin'));
    $this->get(route('request-drafts.edit', $draft->id))->assertNotFound();
    $this->get(route('request-drafts.file', [$draft->id, $fileId]))->assertNotFound();
    $this->putJson(route('request-drafts.update', $draft->id), ['version' => 2, 'payload' => webPayload()])->assertNotFound();
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 2])->assertNotFound();
});

test('uploaded files are attached privately to the submitted request', function () {
    $draft = webDraft();
    $this->postJson(route('request-drafts.upload', $draft->id), ['version' => 1, 'file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')])->assertOk();
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 2])->assertOk();
    $request = ApprovalRequest::first();
    $file = $request->attachments->first();
    Storage::disk('local')->assertExists($file->file_path);
    $this->get(route('request-submissions.file', [$request->id, $file->id]))->assertDownload();
    $this->actingAs(makeUserWithRole('requester'))->get(route('request-submissions.file', [$request->id, $file->id]))->assertNotFound();
});

test('attachment names and sizes survive submission downloads and copying', function () {
    $draft = webDraft();
    $name = 'Счёт на кабель.pdf';
    $content = "%PDF-1.4\nInvoice for cable\n%%EOF";
    $this->postJson(route('request-drafts.upload', $draft->id), [
        'version' => 1, 'file' => UploadedFile::fake()->createWithContent($name, $content),
    ])->assertOk();
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 2])->assertOk();
    $request = ApprovalRequest::first();
    $file = $request->attachments()->sole();
    expect($file->original_name)->toBe($name)->and($file->file_size)->toBe(strlen($content));
    $this->get(route('requests.detail', $request->id))->assertOk()->assertInertia(fn ($page) => $page
        ->where('request.attachments.0.name', $name)->where('request.attachments.0.file_size', strlen($content)));
    $download = $this->get(route('request-submissions.file', [$request->id, $file->id]))->assertDownload();
    expect($download->headers->get('Content-Disposition'))->toContain("filename*=utf-8''".rawurlencode($name));
    $this->post(route('request-submissions.copy', $request->id))->assertRedirect();
    $copy = RequestDraft::where('id', '!=', $draft->id)->sole();
    expect($copy->files()->sole()->name)->toBe($name);
});

test('legacy and unsafe attachment names remain distinct and safe to download', function () {
    $request = app(ApprovalRequestCreation::class)->create($this->author, webPayload());
    $first = $request->attachments()->create(['file_type' => 'pdf', 'file_path' => 'legacy-one.pdf']);
    $second = $request->attachments()->create(['file_type' => 'pdf', 'file_path' => 'legacy-two.pdf']);
    $unsafe = $request->attachments()->create(['file_type' => 'pdf', 'file_path' => 'unsafe.pdf', 'original_name' => "C:\\private\\invoice\r\n.pdf"]);
    expect($first->display_name)->not->toBe($second->display_name)
        ->and($unsafe->display_name)->toBe('invoice.pdf');
    $this->get(route('requests.detail', $request->id))->assertOk()->assertInertia(fn ($page) => $page
        ->where('request.attachments.0.name', $first->display_name)
        ->where('request.attachments.1.name', $second->display_name)
        ->where('request.attachments.2.name', 'invoice.pdf'));
});

test('uploaded images remain available on the submitted request', function () {
    $draft = webDraft();
    $this->postJson(route('request-drafts.upload', $draft->id), [
        'version' => 1, 'file' => UploadedFile::fake()->image('photo.png'),
    ])->assertOk()->assertJsonPath('files.0.file_type', 'png');
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 2])->assertOk();
    $request = ApprovalRequest::first();
    $file = $request->attachments()->sole();
    expect($file->file_type)->toBe('png');
    Storage::disk('local')->assertExists($file->file_path);
    $this->get(route('request-submissions.file', [$request->id, $file->id]))->assertDownload();
});

test('storage failures preserve the draft and permit retry without a phantom attachment', function (bool $throw) {
    $draft = webDraft();
    $disk = Storage::disk('local');
    $broken = \Mockery::mock($disk);
    if ($throw) {
        $broken->shouldReceive('putFileAs')->andThrow(\League\Flysystem\UnableToCreateDirectory::atLocation('attachments/drafts'));
    } else {
        $broken->shouldReceive('putFileAs')->andReturn(false);
    }
    Storage::set('local', $broken);
    $this->postJson(route('request-drafts.upload', $draft->id), [
        'version' => 1, 'file' => UploadedFile::fake()->image('photo.png'),
    ])->assertStatus(503)->assertJsonPath('message', 'Не удалось сохранить вложение. Повторите загрузку. Если ошибка повторится, обратитесь к администратору.');
    expect($draft->fresh()->version)->toBe(1)->and($draft->files()->count())->toBe(0);
    Storage::set('local', $disk);
    $this->postJson(route('request-drafts.upload', $draft->id), [
        'version' => 1, 'file' => UploadedFile::fake()->image('photo.png'),
    ])->assertOk()->assertJsonPath('version', 2)->assertJsonCount(1, 'files');
})->with([false, true]);

test('creation rechecks project and approver permissions and tranche totals', function () {
    $draft = webDraft(webPayload(['project_id' => makeProject('Forbidden')->id]));
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertJsonValidationErrors('project_id');
    $draft->update(['payload' => webPayload(['approvers' => [makeUserWithRole('approver')->id]])]);
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertJsonValidationErrors('approvers');
    $draft->update(['payload' => webPayload(['tranches' => [['amount' => 10, 'due_date' => today()->addDay()->toDateString()]]])]);
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertJsonValidationErrors('tranches');
    $this->author->update(['account_status' => 'rejected']);
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertForbidden();
    expect(ApprovalRequest::count())->toBe(0);
});

test('notification failure leaves a durable retry and a submitted request', function () {
    $draft = webDraft();
    $mock = \Mockery::mock(\SergiX44\Nutgram\Nutgram::class);
    $mock->shouldReceive('sendMessage')->andThrow(new RuntimeException('offline'));
    app()->instance(\SergiX44\Nutgram\Nutgram::class, $mock);
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertOk();
    $request = ApprovalRequest::first();
    app(RequestCreationNotifier::class)->deliver($request->id);
    expect(DB::table('request_creation_notifications')->first()->delivered_at)->toBeNull();
    $spy = fakeBot();
    $this->artisan('requests:notify-created')->assertSuccessful();
    $this->artisan('requests:notify-created')->assertSuccessful();
    expect($spy->messagesTo($this->approver->telegram_id))->toHaveCount(1)
        ->and($draft->fresh()->submitted_request_id)->toBe($request->id);
});

test('first approver sees every item and successful pages are not resent after an attachment failure', function () {
    $items = array_map(fn ($i) => ['name' => 'Позиция '.$i, 'quantity' => 1, 'price_per_unit' => 10], range(1, 9));
    $request = app(ApprovalRequestCreation::class)->create($this->author, webPayload(['items' => $items]));
    $request->attachments()->create(['file_type' => 'pdf', 'telegram_file_id' => 'telegram-file']);
    $messages = [];
    $fileAttempts = 0;
    $bot = \Mockery::mock(\SergiX44\Nutgram\Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function ($text = null) use (&$messages) {
        $messages[] = $text;
    });
    $bot->shouldReceive('sendDocument')->andReturnUsing(function () use (&$fileAttempts) {
        if (++$fileAttempts === 1) {
            throw new RuntimeException('attachment offline');
        }
    });
    app()->instance(\SergiX44\Nutgram\Nutgram::class, $bot);

    $notifier = app(RequestCreationNotifier::class);
    $notifier->deliver($request->id);
    $firstCount = count($messages);
    expect(implode("\n", $messages))->toContain('Позиция 9')
        ->and(DB::table('request_creation_notifications')->value('delivered_at'))->toBeNull();

    $notifier->deliver($request->id);
    expect($messages)->toHaveCount($firstCount)
        ->and($fileAttempts)->toBe(2)
        ->and(DB::table('request_creation_notifications')->value('delivered_at'))->not->toBeNull();
});

test('missing attachment remains pending until its file is available', function () {
    $request = app(ApprovalRequestCreation::class)->create($this->author, webPayload());
    $attachment = $request->attachments()->create(['file_type' => 'pdf', 'file_path' => 'attachments/recovered.pdf']);
    $messages = 0;
    $bot = \Mockery::mock(\SergiX44\Nutgram\Nutgram::class);
    $bot->shouldReceive('sendMessage')->andReturnUsing(function () use (&$messages) {
        $messages++;
    });
    $bot->shouldReceive('sendDocument')->once();
    app()->instance(\SergiX44\Nutgram\Nutgram::class, $bot);

    $notifier = app(RequestCreationNotifier::class);
    $notifier->deliver($request->id);
    $notice = DB::table('request_creation_notifications')->first();
    expect($notice->delivered_at)->toBeNull()
        ->and(json_decode($notice->completed_steps, true))->not->toContain('revision:'.$request->revision.':file:'.$attachment->id);
    $sentPages = $messages;

    Storage::disk('local')->put('attachments/recovered.pdf', 'recovered');
    $notifier->deliver($request->id);
    expect($messages)->toBe($sentPages)
        ->and(DB::table('request_creation_notifications')->value('delivered_at'))->not->toBeNull();
});

test('supply creation follows the automatic route then enters the supply inbox', function () {
    $director = makeUserWithRole('supplier');
    $director->projects()->attach($this->project);
    $draft = webDraft(webPayload(['type' => 'supply', 'approvers' => [], 'items' => [['name' => 'Материал', 'quantity' => 1.5]]]));
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertOk();
    $request = ApprovalRequest::first();
    expect($request->approvals->sortBy('level')->pluck('approver_id')->all())->toBe([$this->approver->id, $director->id]);
    app(ApprovalWorkflow::class)->approve($request->id, $this->approver, null);
    app(ApprovalWorkflow::class)->approve($request->id, $director, null);
    expect($request->fresh()->status)->toBe('pending_supply')->and($request->tranches()->count())->toBe(0);
});

test('a copy keeps rejection history and creates a distinct request', function () {
    $source = app(ApprovalRequestCreation::class)->create($this->author, webPayload());
    $source->update(['status' => 'rejected']);
    $source->approvals()->update(['status' => 'rejected', 'comment' => 'Уточните описание']);
    $this->post(route('request-submissions.copy', $source->id))->assertRedirect();
    $draft = RequestDraft::first();
    expect($draft->source_request_id)->toBe($source->id)->and($draft->payload['deadline_date'])->toBe('');
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertOk();
    expect($source->fresh()->status)->toBe('rejected')->and($source->approvals->first()->comment)->toBe('Уточните описание')
        ->and(ApprovalRequest::count())->toBe(2)
        ->and(ApprovalRequest::find($draft->fresh()->submitted_request_id)->deadline_date->toDateString())->toBe(today()->toDateString());
});

test('PDF analysis uses the existing parser and does not silently change the draft', function () {
    $draft = webDraft();
    $uploaded = $this->postJson(route('request-drafts.upload', $draft->id), ['version' => 1, 'file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')])->assertOk();
    $parsed = new \App\Services\PdfImport\ParsedPaymentRequest('Invoice', '12', null, 100, 30, 'USD', 30, [], [], [], []);
    $this->mock(\App\Services\PdfImport\PaymentRequestPdfImportService::class)->shouldReceive('analyze')->once()->andReturn(['parsed' => $parsed, 'match' => ['project' => $this->project, 'supplier_party' => ['name' => 'PDF Vendor', 'inn' => '123']]]);
    $this->postJson(route('request-drafts.analyze', [$draft->id, $uploaded->json('files.0.id')]))->assertOk()->assertJsonPath('suggestion.amount', 30);
    expect($draft->fresh()->payload['supplier_name'])->toBe('Поставщик');
});

test('Telegram expense adapter preserves timestamp inputs and uses the same creation workflow', function () {
    $conversation = new class extends \App\Telegram\Conversations\CreateApprovalRequest
    {
        protected function end(): void {}

        protected function claimCallbackOnce(\SergiX44\Nutgram\Nutgram $bot, string $action, int $ttl = 600): bool
        {
            return true;
        }
    };
    $bot = app(\SergiX44\Nutgram\Nutgram::class);
    $callback = new \SergiX44\Nutgram\Telegram\Types\Inline\CallbackQuery;
    $callback->data = 'confirm:yes';
    $bot->shouldReceive('userId')->andReturn($this->author->telegram_id);
    $bot->shouldReceive('isCallbackQuery')->andReturn(true);
    $bot->shouldReceive('callbackQuery')->andReturn($callback);
    $bot->shouldReceive('answerCallbackQuery')->andReturnNull();
    $bot->shouldReceive('editMessageReplyMarkup')->andReturnNull();
    $conversation->projectId = $this->project->id;
    $conversation->currency = 'UZS';
    $conversation->transaction_type = 'bank';
    $conversation->supplier_name = 'Bot supplier';
    $conversation->deadlineDate = today()->addDays(3)->format('Y-m-d H:i:s');
    $conversation->items = webPayload()['items'];
    $conversation->approvers = [$this->approver->id];
    $conversation->isPrepayment = true;
    $conversation->tranches = [['amount' => 15, 'percent' => 100, 'due_date' => today()->addDay()->format('Y-m-d H:i:s'), 'comment' => 'Deposit']];
    $conversation->attachmentFileIds = [['file_id' => 'expense-invoice', 'file_type' => 'pdf', 'original_name' => 'Счёт из Telegram.pdf', 'file_size' => 2048]];
    $conversation->handleConfirm($bot);
    $request = ApprovalRequest::first();
    expect($request)->not->toBeNull()->and((float) $request->total_price)->toBe(15.0)
        ->and($request->tranches->first()->due_date->toDateString())->toBe(today()->addDay()->toDateString());
    expect($request->attachments()->sole()->display_name)->toBe('Счёт из Telegram.pdf')
        ->and($request->attachments()->sole()->file_size)->toBe(2048);
});

test('Telegram supply adapter creates the same automatic route', function () {
    $director = makeUserWithRole('supplier');
    $director->projects()->attach($this->project);
    $conversation = new class extends \App\Telegram\Conversations\CreateSupplyRequest
    {
        protected function end(): void {}

        public function saveForTest(\SergiX44\Nutgram\Nutgram $bot): void
        {
            $this->saveRequest($bot);
        }
    };
    $bot = app(\SergiX44\Nutgram\Nutgram::class);
    $bot->shouldReceive('userId')->andReturn($this->author->telegram_id);
    $conversation->projectId = $this->project->id;
    $conversation->deadlineDate = today()->addDay()->format('Y-m-d H:i:s');
    $conversation->items = [['name' => 'Supply item', 'description' => '', 'quantity' => 1.5]];
    $conversation->attachmentFileIds = [['file_id' => 'supply-quote', 'file_type' => 'pdf', 'original_name' => 'Предложение поставщика.pdf', 'file_size' => 4096]];
    $conversation->saveForTest($bot);
    $request = ApprovalRequest::first();
    expect($request)->not->toBeNull()->and($request->approvals->sortBy('level')->pluck('approver_id')->all())->toBe([$this->approver->id, $director->id]);
    expect($request->attachments()->sole()->display_name)->toBe('Предложение поставщика.pdf')
        ->and($request->attachments()->sole()->file_size)->toBe(4096);
});

test('attachment forwarding supports both Telegram photos and private web documents', function () {
    $request = app(ApprovalRequestCreation::class)->create($this->author, webPayload());
    $request->attachments()->create(['file_type' => 'jpg', 'telegram_file_id' => 'telegram-photo']);
    Storage::disk('local')->put('attachments/web.pdf', 'fake PDF');
    $request->attachments()->create(['file_type' => 'pdf', 'file_path' => 'attachments/web.pdf']);
    $bot = app(\SergiX44\Nutgram\Nutgram::class);
    $bot->shouldReceive('sendPhoto')->once()->andReturnNull();
    $bot->shouldReceive('sendDocument')->once()->andReturnNull();
    app(RequestCreationNotifier::class)->forwardAttachments($request->fresh(), $this->approver);
});

test('web-only project participants can complete the supply route without Telegram', function () {
    $this->author->update(['telegram_id' => null]);
    $this->approver->update(['telegram_id' => null]);
    $director = makeUserWithRole('supplier', ['telegram_id' => null]);
    $director->projects()->attach($this->project);
    $draft = webDraft(webPayload(['type' => 'supply']));
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertOk();
    $request = ApprovalRequest::first();
    expect($request->approvals->sortBy('level')->pluck('approver_id')->all())->toBe([$this->approver->id, $director->id]);
    app(ApprovalWorkflow::class)->approve($request->id, $this->approver, null, $request->revision);
    $this->actingAs($director)->get(route('requests.detail', $request->id))->assertOk()->assertInertia(fn ($page) => $page
        ->where('request.can_approve', true));
    app(ApprovalWorkflow::class)->approve($request->id, $director, null, $request->fresh()->revision);
    expect($request->fresh()->status)->toBe('pending_supply');
});

test('Telegram reports excessive decimal places before the final submission step', function () {
    $bot = app(\SergiX44\Nutgram\Nutgram::class);
    $bot->shouldReceive('userId')->andReturn($this->author->telegram_id);
    $message = new \SergiX44\Nutgram\Telegram\Types\Message\Message;
    $message->text = '1.2345';
    $bot->shouldReceive('message')->andReturn($message);
    $expense = new \App\Telegram\Conversations\CreateApprovalRequest;
    $expense->handleItemPrice($bot);
    $expense->handleItemQty($bot);
    $supply = new \App\Telegram\Conversations\CreateSupplyRequest;
    $supply->handleItemQty($bot);
    expect($expense->currentItemPrice)->toBeNull()->and($expense->currentItemQty)->toBeNull()
        ->and($supply->currentItemQty)->toBeNull()->and($this->bot->messages)->toHaveCount(3);
});

test('draft removal checks owner and version and retains referenced attachments', function () {
    $draft = webDraft();
    Storage::disk('local')->put('attachments/shared.pdf', 'pdf');
    app(ApprovalRequestCreation::class)->create($this->author, webPayload(), [['file_type' => 'pdf', 'file_path' => 'attachments/shared.pdf']]);
    $draft->files()->create(['name' => 'shared.pdf', 'path' => 'attachments/shared.pdf', 'file_type' => 'pdf']);
    $this->deleteJson(route('request-drafts.destroy', $draft->id), ['version' => 9])->assertConflict();
    $this->actingAs(makeUserWithRole('admin'))->deleteJson(route('request-drafts.destroy', $draft->id), ['version' => 1])->assertNotFound();
    $this->actingAs($this->author)->delete(route('request-drafts.destroy', $draft->id), ['version' => 1])->assertRedirect();
    expect(RequestDraft::find($draft->id))->toBeNull();
    Storage::disk('local')->assertExists('attachments/shared.pdf');
});

test('copies preserve local and Telegram attachments through download and submission', function () {
    Storage::disk('local')->put('attachments/local.pdf', 'local invoice');
    $source = app(ApprovalRequestCreation::class)->create($this->author, webPayload(), [
        ['file_type' => 'pdf', 'file_path' => 'attachments/local.pdf', 'original_name' => 'Local.pdf'],
        ['file_type' => 'pdf', 'telegram_file_id' => 'telegram_invoice_123', 'original_name' => 'Telegram.pdf'],
        ['file_type' => 'pdf', 'file_path' => 'attachments/missing.pdf', 'telegram_file_id' => 'telegram_fallback_123', 'original_name' => 'Fallback.pdf'],
    ]);
    $this->post(route('request-submissions.copy', $source->id))->assertRedirect();
    $draft = RequestDraft::where('source_request_id', $source->id)->sole();
    expect($draft->files()->count())->toBe(3);
    $file = $draft->files()->where('telegram_file_id', 'telegram_invoice_123')->sole();
    $this->get(route('request-drafts.edit', $draft->id))->assertInertia(fn ($page) => $page
        ->where('draft.files.1.url', route('request-drafts.file', [$draft->id, $file->id])));
    $this->mock(\App\Services\TelegramFileCache::class)->shouldReceive('download')->once()
        ->with('', 'telegram_invoice_123', 'Telegram.pdf')
        ->andReturnUsing(fn () => Storage::disk('local')->download('attachments/local.pdf'));
    $this->get(route('request-drafts.file', [$draft->id, $file->id]))->assertDownload();
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 1])->assertOk();
    $copy = ApprovalRequest::findOrFail($draft->fresh()->submitted_request_id);
    expect($copy->attachments()->count())->toBe(3)
        ->and($copy->attachments()->where('telegram_file_id', 'telegram_invoice_123')->sole()->original_name)->toBe('Telegram.pdf')
        ->and($copy->attachments()->where('telegram_file_id', 'telegram_fallback_123')->exists())->toBeTrue();
});

test('removing drafts deletes only unreferenced files after commit', function (string $reference) {
    $draft = webDraft();
    $unique = 'attachments/drafts/'.$draft->id.'/unique.pdf';
    $shared = 'attachments/drafts/'.$draft->id.'/shared.pdf';
    foreach ([$unique, $shared] as $path) {
        Storage::disk('local')->put($path, 'fixture');
        $draft->files()->create(['name' => basename($path), 'path' => $path, 'file_type' => 'pdf']);
    }
    if ($reference === 'other draft') {
        webDraft()->files()->create(['name' => 'shared.pdf', 'path' => $shared, 'file_type' => 'pdf']);
    } else {
        $source = app(ApprovalRequestCreation::class)->create($this->author, webPayload(),
            $reference === 'document source' ? [] : [['file_type' => 'pdf', 'file_path' => $shared]]);
        if ($reference === 'history') {
            app(\App\Services\RequestHistory::class)->archive($source);
            $source->attachments()->update(['archived_at' => now()]);
        } elseif ($reference === 'deleted request') {
            $source->delete();
        } elseif ($reference === 'document source') {
            $source->update(['source_docx_path' => $shared]);
        }
    }
    $this->delete(route('request-drafts.destroy', $draft->id), ['version' => 1])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('request_drafts', ['id' => $draft->id]);
    $this->assertDatabaseMissing('request_draft_files', ['request_draft_id' => $draft->id]);
    Storage::disk('local')->assertMissing($unique);
    Storage::disk('local')->assertExists($shared);
})->with(['other draft', 'active request', 'history', 'deleted request', 'document source']);

test('rolling back draft deletion also cancels physical file cleanup', function () {
    $draft = webDraft();
    $path = 'attachments/drafts/'.$draft->id.'/keep.pdf';
    Storage::disk('local')->put($path, 'fixture');
    $draft->files()->create(['name' => 'keep.pdf', 'path' => $path, 'file_type' => 'pdf']);
    try {
        DB::transaction(function () use ($draft, $path) {
            $this->delete(route('request-drafts.destroy', $draft->id), ['version' => 1])->assertRedirect();
            Storage::disk('local')->assertExists($path);
            throw new RuntimeException('Rollback deletion');
        });
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Rollback deletion');
    }
    expect($draft->fresh())->not->toBeNull()->and($draft->files()->count())->toBe(1);
    Storage::disk('local')->assertExists($path);
});

test('returned request is revised under the same id with preserved decisions and fresh approvals', function () {
    $r = app(ApprovalRequestCreation::class)->create($this->author, webPayload());
    $this->actingAs($this->approver)->post(route('requests.request-changes', $r->id), ['reason' => 'Уточнить количество', 'revision' => 1])->assertRedirect();
    expect($r->fresh()->status)->toBe('revision_requested');
    $this->actingAs($this->author)->post(route('request-submissions.revise', $r->id))->assertRedirect();
    $draft = RequestDraft::where('is_revision', true)->firstOrFail();
    $this->post(route('request-submissions.revise', $r->id))->assertRedirect(route('request-drafts.edit', $draft->id));
    $payload = $draft->payload;
    $payload['items'][0]['quantity'] = '2';
    $payload['tranches'] = [];
    $this->putJson(route('request-drafts.update', $draft->id), ['version' => 1, 'payload' => $payload])->assertOk();
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 2])->assertOk();
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => 2])->assertOk();
    expect(ApprovalRequest::count())->toBe(1)->and($r->fresh()->revision)->toBe(2)->and($r->fresh()->status)->toBe('pending_approval')->and((float) $r->fresh()->total_price)->toBe(20.0);
    $snapshot = json_decode(DB::table('request_revisions')->where('approval_request_id', $r->id)->value('snapshot'), true);
    expect($snapshot['approvals'][0]['comment'])->toContain('Уточнить')->and($r->fresh()->approvals->first()->status)->toBe('pending');
    $this->get(route('requests.history', $r->id))->assertOk();
    $this->actingAs(makeUserWithRole('requester'))->get(route('requests.history', $r->id))->assertNotFound();
    expect(fn () => app(ApprovalWorkflow::class)->approve($r->id, $this->approver, null, 1))->toThrow(\Illuminate\Validation\ValidationException::class);
    app(ApprovalWorkflow::class)->approve($r->id, $this->approver, null, 2);
    expect($r->fresh()->status)->toBe('approved');
});

test('only current signer can return a request and paid requests cannot be revised', function () {
    $r = app(ApprovalRequestCreation::class)->create($this->author, webPayload());
    $this->postJson(route('requests.request-changes', $r->id), ['reason' => 'No', 'revision' => 1])->assertForbidden();
    $this->actingAs($this->approver)->postJson(route('requests.request-changes', $r->id), ['reason' => '', 'revision' => 1])->assertUnprocessable();
    $this->postJson(route('requests.request-changes', $r->id), ['reason' => 'No', 'revision' => 9])->assertConflict();
    $r->update(['status' => 'rejected']);
    $r->tranches()->update(['status' => 'paid']);
    $this->actingAs($this->author)->postJson(route('request-submissions.revise', $r->id))->assertConflict();
});

test('an obsolete revision draft cannot overwrite a later rejected revision', function () {
    $r = app(ApprovalRequestCreation::class)->create($this->author, webPayload());
    $r->update(['status' => 'rejected']);
    $this->post(route('request-submissions.revise', $r->id))->assertRedirect();
    $d = RequestDraft::first();
    $r->update(['revision' => 2]);
    $this->postJson(route('request-drafts.submit', $d->id), ['version' => 1])->assertConflict();
    expect($r->fresh()->status)->toBe('rejected')->and(DB::table('request_revisions')->count())->toBe(0);
});

test('stale web approval and rejection do not change the new version', function () {
    $r = app(ApprovalRequestCreation::class)->create($this->author, webPayload());
    $r->update(['revision' => 2]);
    $this->actingAs($this->approver);
    $this->postJson(route('requests.approve', $r->id), ['revision' => 1])->assertUnprocessable();
    $this->post(route('requests.reject', $r->id), ['revision' => 1, 'reason' => 'Old decision'])->assertRedirect();
    expect($r->fresh()->status)->toBe('pending_approval')->and($r->fresh()->approvals->first()->status)->toBe('pending');
    $this->post(route('requests.reject', $r->id), ['revision' => 2, 'reason' => 'Current decision'])->assertRedirect();
    expect($r->fresh()->status)->toBe('rejected');
});

test('revision replaces active attachments while keeping private historical files', function () {
    Storage::disk('local')->put('attachments/wrong.pdf', 'wrong invoice');
    $r = app(ApprovalRequestCreation::class)->create($this->author, webPayload(), [
        ['file_type' => 'pdf', 'file_path' => 'attachments/wrong.pdf'],
        ['file_type' => 'document', 'telegram_file_id' => 'telegram-only-file'],
    ]);
    $old = $r->attachments()->whereNotNull('file_path')->first();
    $r->update(['status' => 'rejected']);
    $this->post(route('request-submissions.revise', $r->id))->assertRedirect();
    $draft = RequestDraft::where('is_revision', true)->firstOrFail();
    expect($draft->files()->count())->toBe(2);
    $this->get(route('request-drafts.edit', $draft->id))->assertOk();
    expect($draft->files()->count())->toBe(2);
    $file = $draft->files()->where('path', 'attachments/wrong.pdf')->first();
    $this->deleteJson(route('request-drafts.remove-file', [$draft->id, $file->id]), ['version' => $draft->version])->assertOk();
    Storage::disk('local')->assertExists('attachments/wrong.pdf');
    $this->postJson(route('request-drafts.upload', $draft->id), ['version' => $draft->fresh()->version, 'file' => UploadedFile::fake()->create('correct.pdf', 10, 'application/pdf')])->assertOk();
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => $draft->fresh()->version])->assertOk();
    expect($r->fresh()->revision)->toBe(2)->and($r->attachments()->count())->toBe(2)
        ->and($r->attachments()->where('file_path', 'attachments/wrong.pdf')->exists())->toBeFalse()
        ->and($r->attachments()->where('telegram_file_id', 'telegram-only-file')->exists())->toBeTrue();
    expect(DB::table('request_attachments')->where('id', $old->id)->value('archived_at'))->not->toBeNull();
    $this->get(route('request-submissions.file', [$r->id, $old->id]))->assertNotFound();
    $this->get(route('requests.history-file', [$r->id, 1, $old->id]))->assertDownload();
    $this->actingAs(makeUserWithRole('requester'))->get(route('requests.history-file', [$r->id, 1, $old->id]))->assertNotFound();
});

test('existing revision drafts import old attachments once and allow removing Telegram only files', function () {
    $r = app(ApprovalRequestCreation::class)->create($this->author, webPayload(), [['file_type' => 'document', 'telegram_file_id' => 'old-telegram']]);
    $r->update(['status' => 'rejected']);
    $draft = webDraft(webPayload());
    $draft->update(['is_revision' => true, 'source_request_id' => $r->id, 'source_revision' => 1]);
    $this->get(route('request-drafts.edit', $draft->id))->assertOk();
    expect($draft->files()->count())->toBe(1);
    $file = $draft->files()->first();
    Storage::disk('local')->put('telegram-cache/test-file.pdf', 'file');
    $this->mock(\App\Services\TelegramFileCache::class)->shouldReceive('download')->once()
        ->with($file->path, $file->telegram_file_id, $file->name)
        ->andReturnUsing(fn () => Storage::disk('local')->download('telegram-cache/test-file.pdf'));
    $this->get(route('request-drafts.file', [$draft->id, $file->id]))->assertDownload();
    $this->deleteJson(route('request-drafts.remove-file', [$draft->id, $file->id]), ['version' => 1])->assertOk();
    $this->get(route('request-drafts.edit', $draft->id))->assertOk();
    expect($draft->files()->count())->toBe(0);
    $this->postJson(route('request-drafts.submit', $draft->id), ['version' => $draft->fresh()->version])->assertOk();
    expect($r->attachments()->count())->toBe(0)->and($r->fresh()->revision)->toBe(2);
});

test('create menu starts the selected draft type and rejects unknown types', function () {
    $this->post(route('request-drafts.store'), ['type' => 'supply'])->assertRedirect();
    expect(RequestDraft::first()->payload['type'])->toBe('supply');
    $this->postJson(route('request-drafts.store'), ['type' => 'unknown'])->assertUnprocessable();
    expect(RequestDraft::count())->toBe(1);
});
