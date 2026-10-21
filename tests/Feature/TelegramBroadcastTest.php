<?php

use App\Jobs\SendTelegramBroadcastJob;
use App\Models\TelegramBroadcast;
use App\Services\TelegramBroadcastService;
use App\Telegram\Conversations\BroadcastNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Inline\CallbackQuery;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

beforeEach(function () {
    seedRoles();
});

function broadcastBot(int $telegramId, ?string $text = null, ?string $callback = null): Nutgram
{
    $message = new Message;
    $message->text = $text;
    $query = new CallbackQuery;
    $query->data = $callback;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('userId')->andReturn($telegramId);
    $bot->shouldReceive('message')->andReturn($message);
    $bot->shouldReceive('callbackQuery')->andReturn($callback === null ? null : $query);
    $bot->shouldReceive('isCallbackQuery')->andReturn($callback !== null);
    $bot->shouldReceive('update')->andReturnNull();
    $bot->shouldReceive('stepConversation', 'endConversation')->andReturnSelf();
    $bot->shouldReceive('answerCallbackQuery', 'editMessageReplyMarkup')->andReturnNull();
    $bot->shouldReceive('sendMessage')->andReturn(new Message)->byDefault();

    return $bot;
}

function queuedBroadcast(array $recipients, string $text = 'Новое уведомление'): TelegramBroadcast
{
    $admin = makeUserWithRole('admin');

    return TelegramBroadcast::create([
        'created_by' => $admin->id,
        'admin_chat_id' => $admin->telegram_id,
        'text' => $text,
        'recipients' => $recipients,
        'status' => 'queued',
    ]);
}

function notificationHtml(string $text): string
{
    return app(App\Services\TelegramNotificationMessage::class)->single($text);
}

test('broadcast preview snapshots only approved Telegram users', function () {
    $admin = makeUserWithRole('admin');
    $recipient = makeUserWithRole('requester');
    makeUserWithRole('requester', ['account_status' => 'pending']);
    makeUserWithRole('requester', ['account_status' => 'rejected']);
    makeUserWithRole('requester', ['account_status' => 'new']);
    makeUserWithRole('requester', ['telegram_id' => null]);

    $draft = app(TelegramBroadcastService::class)->createDraft($admin, '  Новый канал https://t.me/+test_invitation <текст> & сообщение  ');
    makeUserWithRole('requester');

    expect($draft->recipients)->toBe([$admin->telegram_id, $recipient->telegram_id])
        ->and($draft->status)->toBe('draft')
        ->and($draft->text)->toBe('Новый канал https://t.me/+test_invitation <текст> & сообщение');
});

test('only approved admins and superadmins can draft or launch a broadcast', function (string $role, string $status) {
    $actor = makeUserWithRole($role, ['account_status' => $status]);
    $draft = queuedBroadcast([]);
    Queue::fake();

    expect(fn () => app(TelegramBroadcastService::class)->createDraft($actor, 'Hello'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(TelegramBroadcastService::class)->queue($actor, $draft->id))
        ->toThrow(AuthorizationException::class);
    Queue::assertNothingPushed();
})->with([
    ['requester', 'approved'], ['supplier', 'approved'], ['approver', 'approved'],
    ['admin', 'pending'], ['superadmin', 'rejected'],
]);

test('confirmation queues exactly once and always uses a background database queue', function (string $role) {
    Queue::fake();
    $admin = makeUserWithRole($role);
    $service = app(TelegramBroadcastService::class);
    $draft = $service->createDraft($admin, 'Hello');

    expect($service->queue($admin, $draft->id))->toBeTrue()
        ->and($service->queue($admin, $draft->id))->toBeFalse()
        ->and($draft->fresh()->status)->toBe('queued');
    Queue::assertPushed(SendTelegramBroadcastJob::class, 1);
    Queue::assertPushed(SendTelegramBroadcastJob::class, fn ($job) => $job->connection === 'database' && $job->broadcastId === $draft->id);
})->with(['admin', 'superadmin']);

test('another admin cannot send a draft and cancelled drafts cannot be sent', function () {
    Queue::fake();
    $admin = makeUserWithRole('admin');
    $other = makeUserWithRole('admin');
    $service = app(TelegramBroadcastService::class);
    $draft = $service->createDraft($admin, 'Hello');

    expect($service->queue($other, $draft->id))->toBeFalse();
    $draft->update(['status' => 'cancelled']);
    expect($service->queue($admin, $draft->id))->toBeFalse();
    Queue::assertNothingPushed();
});

test('confirmation persists a single database job even when the default queue is synchronous', function () {
    config(['queue.default' => 'sync']);
    $admin = makeUserWithRole('admin');
    $service = app(TelegramBroadcastService::class);
    $draft = $service->createDraft($admin, 'Hello');

    expect($service->queue($admin, $draft->id))->toBeTrue()
        ->and($service->queue($admin, $draft->id))->toBeFalse();
    $this->assertDatabaseCount('jobs', 1);
    $payload = json_decode(Illuminate\Support\Facades\DB::table('jobs')->value('payload'), true);
    expect($payload['displayName'])->toBe(SendTelegramBroadcastJob::class)
        ->and($draft->fresh()->status)->toBe('queued');
});

test('empty and oversized notification texts cannot be broadcast', function (string $text) {
    $admin = makeUserWithRole('admin');
    expect(fn () => app(TelegramBroadcastService::class)->createDraft($admin, $text))->toThrow(InvalidArgumentException::class);
    expect(TelegramBroadcast::count())->toBe(0);
})->with(['   ', str_repeat('я', 4097)]);

test('conversation previews the text without launching a broadcast', function () {
    Queue::fake();
    $admin = makeUserWithRole('admin');
    $bot = broadcastBot($admin->telegram_id, 'Hello <everyone> & https://t.me/+test_invitation');
    $bot->shouldReceive('sendMessage')->once()->with(notificationHtml('Hello <everyone> & https://t.me/+test_invitation'), null, null, 'HTML')->andReturn(new Message);
    $conversation = new BroadcastNotification;
    $conversation($bot);
    $conversation($bot);

    expect(TelegramBroadcast::sole()->status)->toBe('draft');
    Queue::assertNothingPushed();
});

test('conversation supports cancelling from the preview by button or command', function (string $input) {
    Queue::fake();
    $admin = makeUserWithRole('admin');
    $bot = broadcastBot($admin->telegram_id, 'Hello');
    $conversation = new BroadcastNotification;
    $conversation($bot);
    $conversation($bot);
    $draft = TelegramBroadcast::sole();
    $cancelBot = $input === 'button'
        ? broadcastBot($admin->telegram_id, callback: 'broadcast:cancel:'.$draft->id)
        : broadcastBot($admin->telegram_id, '/cancel');
    $conversation($cancelBot);

    expect($draft->fresh()->status)->toBe('cancelled');
    Queue::assertNothingPushed();
})->with(['button', 'command']);

test('conversation supports cancelling before entering text', function () {
    Queue::fake();
    $admin = makeUserWithRole('admin');
    $conversation = new BroadcastNotification;
    $conversation(broadcastBot($admin->telegram_id));
    $conversation(broadcastBot($admin->telegram_id, callback: 'broadcast:cancel'));

    expect(TelegramBroadcast::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('conversation rechecks admin permissions before confirmation', function () {
    Queue::fake();
    $admin = makeUserWithRole('admin');
    $bot = broadcastBot($admin->telegram_id, 'Hello');
    $conversation = new BroadcastNotification;
    $conversation($bot);
    $conversation($bot);
    $draft = TelegramBroadcast::sole();
    $admin->update(['account_status' => 'rejected']);
    $conversation(broadcastBot($admin->telegram_id, callback: 'broadcast:send:'.$draft->id));

    expect($draft->fresh()->status)->toBe('cancelled');
    Queue::assertNothingPushed();
});

test('conversation confirmation queues the reviewed message', function () {
    Queue::fake();
    $admin = makeUserWithRole('admin');
    $conversation = new BroadcastNotification;
    $bot = broadcastBot($admin->telegram_id, 'Hello');
    $conversation($bot);
    $conversation($bot);
    $draft = TelegramBroadcast::sole();
    $conversation(broadcastBot($admin->telegram_id, callback: 'broadcast:send:'.$draft->id));

    expect($draft->fresh()->status)->toBe('queued');
    Queue::assertPushed(SendTelegramBroadcastJob::class, 1);
});

test('blocked recipients do not stop delivery and the admin receives a report', function () {
    $blocked = makeUserWithRole('requester');
    $available = makeUserWithRole('requester');
    $revoked = makeUserWithRole('requester', ['account_status' => 'rejected']);
    $broadcast = queuedBroadcast([$blocked->telegram_id, $available->telegram_id, $revoked->telegram_id], 'Text <literal> & link');
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->with(notificationHtml('Text <literal> & link'), $blocked->telegram_id, null, 'HTML')
        ->andThrow(new TelegramException('Forbidden: bot was blocked', 403));
    $bot->shouldReceive('sendMessage')->once()->with(notificationHtml('Text <literal> & link'), $available->telegram_id, null, 'HTML')->andReturn(new Message);
    $bot->shouldReceive('sendMessage')->once()->with(notificationHtml(__('bot.adm_broadcast_completed', ['sent' => 1, 'failed' => 2], 'ru')), $broadcast->admin_chat_id, null, 'HTML')->andReturn(new Message);
    $job = new SendTelegramBroadcastJob($broadcast->id);
    $job->handle($bot);
    $job->handle($bot);

    expect($broadcast->fresh()->status)->toBe('completed')
        ->and($broadcast->fresh()->sent_count)->toBe(1)
        ->and($broadcast->fresh()->failed_count)->toBe(2)
        ->and($broadcast->fresh()->report_sent_at)->not->toBeNull();
});

test('Telegram flood control delays the same recipient without repeating earlier deliveries', function (bool $wrapped) {
    $first = makeUserWithRole('requester');
    $second = makeUserWithRole('requester');
    $broadcast = queuedBroadcast([$first->telegram_id, $second->telegram_id]);
    $original = new TelegramException('Too many requests', 429, parameters: ['retry_after' => 17]);
    $exception = $wrapped ? new TelegramException('Too many requests', 429, $original) : $original;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->with(notificationHtml($broadcast->text), $first->telegram_id, null, 'HTML')->andReturn(new Message);
    $bot->shouldReceive('sendMessage')->once()->with(notificationHtml($broadcast->text), $second->telegram_id, null, 'HTML')->andThrow($exception);
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(17);
    $job = new SendTelegramBroadcastJob($broadcast->id);
    $job->setJob($queueJob)->handle($bot);

    expect($broadcast->fresh()->cursor)->toBe(1)->and($broadcast->fresh()->failed_count)->toBe(0);
    $resume = Mockery::mock(Nutgram::class);
    $resume->shouldReceive('sendMessage')->once()->with(notificationHtml($broadcast->text), $second->telegram_id, null, 'HTML')->andReturn(new Message);
    $resume->shouldReceive('sendMessage')->once()->with(notificationHtml(__('bot.adm_broadcast_completed', ['sent' => 2, 'failed' => 0], 'ru')), $broadcast->admin_chat_id, null, 'HTML')->andReturn(new Message);
    $job->handle($resume);
    expect($broadcast->fresh()->sent_count)->toBe(2)->and($broadcast->fresh()->status)->toBe('completed');
})->with([false, true]);

test('broadcast yields after a short batch and resumes from the saved cursor', function () {
    $recipients = [];
    for ($i = 0; $i < 6; $i++) {
        $recipients[] = makeUserWithRole('requester')->telegram_id;
    }
    $broadcast = queuedBroadcast($recipients);
    $bot = Mockery::mock(Nutgram::class);
    foreach (array_slice($recipients, 0, 5) as $id) {
        $bot->shouldReceive('sendMessage')->once()->with(notificationHtml($broadcast->text), $id, null, 'HTML')->andReturn(new Message);
    }
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(1);
    $job = new SendTelegramBroadcastJob($broadcast->id);
    $job->setJob($queueJob)->handle($bot);
    expect($broadcast->fresh()->cursor)->toBe(5)->and($broadcast->fresh()->status)->toBe('sending');

    $resume = Mockery::mock(Nutgram::class);
    $resume->shouldReceive('sendMessage')->once()->with(notificationHtml($broadcast->text), $recipients[5], null, 'HTML')->andReturn(new Message);
    $resume->shouldReceive('sendMessage')->once()->with(notificationHtml(__('bot.adm_broadcast_completed', ['sent' => 6, 'failed' => 0], 'ru')), $broadcast->admin_chat_id, null, 'HTML')->andReturn(new Message);
    $job->handle($resume);
    expect($broadcast->fresh()->sent_count)->toBe(6)->and($broadcast->fresh()->status)->toBe('completed');
});

test('transient send errors leave the current recipient pending', function () {
    $recipient = makeUserWithRole('requester');
    $broadcast = queuedBroadcast([$recipient->telegram_id]);
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->andThrow(new TelegramException('Server error', 500));

    expect(fn () => (new SendTelegramBroadcastJob($broadcast->id))->handle($bot))->toThrow(TelegramException::class);
    expect($broadcast->fresh()->cursor)->toBe(0)->and($broadcast->fresh()->failed_count)->toBe(0);
});

test('a null Telegram response is retried rather than counted as delivered', function () {
    $recipient = makeUserWithRole('requester');
    $broadcast = queuedBroadcast([$recipient->telegram_id]);
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->andReturnNull();

    expect(fn () => (new SendTelegramBroadcastJob($broadcast->id))->handle($bot))->toThrow(RuntimeException::class);
    expect($broadcast->fresh()->cursor)->toBe(0)->and($broadcast->fresh()->sent_count)->toBe(0);
});

test('report retries do not resend the broadcast to users', function () {
    $recipient = makeUserWithRole('requester');
    $broadcast = queuedBroadcast([$recipient->telegram_id]);
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->with(notificationHtml($broadcast->text), $recipient->telegram_id, null, 'HTML')->andReturn(new Message);
    $report = notificationHtml(__('bot.adm_broadcast_completed', ['sent' => 1, 'failed' => 0], 'ru'));
    $bot->shouldReceive('sendMessage')->once()->with($report, $broadcast->admin_chat_id, null, 'HTML')->andThrow(new TelegramException('Server error', 500));
    $job = new SendTelegramBroadcastJob($broadcast->id);
    expect(fn () => $job->handle($bot))->toThrow(TelegramException::class);

    $resume = Mockery::mock(Nutgram::class);
    $resume->shouldReceive('sendMessage')->once()->with($report, $broadcast->admin_chat_id, null, 'HTML')->andReturn(new Message);
    $job->handle($resume);
    expect($broadcast->fresh()->sent_count)->toBe(1)->and($broadcast->fresh()->report_sent_at)->not->toBeNull();
});

test('a failed broadcast reports delivered and remaining counts', function () {
    $broadcast = queuedBroadcast([100, 200]);
    $broadcast->update(['cursor' => 1, 'sent_count' => 1]);
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')->once()->with(
        notificationHtml(__('bot.adm_broadcast_failed', ['sent' => 1, 'failed' => 0, 'remaining' => 1], 'ru')),
        $broadcast->admin_chat_id, null, 'HTML'
    )->andReturn(new Message);
    app()->instance(Nutgram::class, $bot);
    $job = new SendTelegramBroadcastJob($broadcast->id);
    $job->failed(new RuntimeException('Queue stopped'));
    $job->handle($bot);

    expect($broadcast->fresh()->status)->toBe('failed')
        ->and($broadcast->fresh()->report_sent_at)->not->toBeNull();
});
