<?php

use App\Models\ApprovalRequest;
use App\Services\RequestDescriptionMessage;
use App\Telegram\Conversations\MyRequests;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Inline\CallbackQuery;

beforeEach(function () {
    seedRoles();
    $this->author = makeUserWithRole('requester');
    $this->request = ApprovalRequest::create([
        'applicant_id' => $this->author->id, 'project_id' => makeProject()->id,
        'type' => 'approval', 'status' => 'pending_approval', 'name' => 'VPS хостинг',
        'currency' => 'UZS', 'total_price' => 660000,
    ]);
});

test('my requests shows the item description even when the request description is empty', function () {
    $this->request->items()->create(['name' => 'VPS хостинг', 'description' => 'Хостинг <сервера> & поддержка', 'quantity' => 1]);
    $spy = fakeBot();
    $bot = app(Nutgram::class);
    $query = new CallbackQuery;
    $query->data = 'detail:'.$this->request->id;
    $bot->shouldReceive('isCallbackQuery')->andReturn(true);
    $bot->shouldReceive('callbackQuery')->andReturn($query);
    $bot->shouldReceive('userId')->andReturn($this->author->telegram_id);
    $bot->shouldReceive('answerCallbackQuery')->once();
    $conversation = new class extends MyRequests
    {
        protected function end(): void {}
    };
    $conversation->handleDetail($bot);

    expect($spy->messages)->toHaveCount(1);
    expect($spy->messages[0]['text'])->toContain('Описание:</b> Хостинг &lt;сервера&gt; &amp; поддержка');
    expect($spy->messages[0]['reply_markup'])->not->toBeNull();
});

test('descriptions preserve the request text and identify multiple positions', function () {
    $this->request->update(['description' => 'Общее описание']);
    $this->request->items()->create(['name' => 'VPS', 'description' => 'Хостинг', 'quantity' => 1]);
    $this->request->items()->create(['name' => 'SSL', 'description' => 'Сертификат', 'quantity' => 1]);
    $pages = app(RequestDescriptionMessage::class)->pages($this->request, 'ru');
    expect(implode("\n", $pages))->toContain('Общее описание', 'VPS: Хостинг', 'SSL: Сертификат');
});

test('long descriptions are escaped and paginated without losing text', function () {
    $description = str_repeat('<b>&текст</b>', 1000);
    $this->request->items()->create(['name' => 'VPS', 'description' => $description, 'quantity' => 1]);
    $pages = app(RequestDescriptionMessage::class)->pages($this->request, 'en');
    expect(count($pages))->toBeGreaterThan(1);
    $decoded = '';
    foreach ($pages as $page) {
        expect(mb_strlen($page))->toBeLessThanOrEqual(3000);
        expect($page)->not->toContain('<b>&текст</b>');
        $decoded .= html_entity_decode(str_replace(__('bot.my_detail_desc', ['desc' => ''], 'en'), '', $page), ENT_QUOTES, 'UTF-8');
    }
    expect(str_replace("\n", '', $decoded))->toBe($description);
});

test('requests without any description display a dash', function () {
    expect(app(RequestDescriptionMessage::class)->pages($this->request, 'ru'))
        ->toBe([__('bot.my_detail_desc', ['desc' => '—'], 'ru')]);
});
