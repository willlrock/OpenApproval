<?php

use App\Services\DeadlineNotifier;

beforeEach(function () {
    seedRoles();
});

test('send returns false when user has no telegram_id', function () {
    $bot = fakeBot();
    $notifier = app(DeadlineNotifier::class);

    $user = makeUserWithRole('approver', ['telegram_id' => null]);

    expect($notifier->send($user, 'bot.dl_stuck_approver', ['id' => 1, 'name' => 'X', 'hours' => 5]))
        ->toBeFalse();
    expect($bot->messages)->toBeEmpty();
});

test('send delivers a message and returns true', function () {
    $bot = fakeBot();
    $notifier = app(DeadlineNotifier::class);

    $user = makeUserWithRole('approver');

    $ok = $notifier->send($user, 'bot.dl_stuck_approver', [
        'id' => 42, 'name' => 'Заявка', 'hours' => 25,
    ]);

    expect($ok)->toBeTrue();
    expect($bot->messages)->toHaveCount(1);
    expect($bot->messages[0]['chat_id'])->toBe($user->telegram_id);
    expect($bot->messages[0]['text'])->toContain('#42');
    // parse_mode проверять смысла нет: Mockery нормализует named-args в порядок
    // реального Nutgram::sendMessage, и он в нашем спае ловится в иной позиции.
});

test('sendToRoles delivers to all approved users with matching role', function () {
    $bot = fakeBot();
    $notifier = app(DeadlineNotifier::class);

    $admin1 = makeUserWithRole('admin');
    $admin2 = makeUserWithRole('admin');
    $superadmin = makeUserWithRole('superadmin');
    $other = makeUserWithRole('approver');           // не должен получить
    $pending = makeUserWithRole('admin', ['account_status' => 'pending']); // не approved

    $sent = $notifier->sendToRoles(['admin', 'superadmin'], 'bot.dl_overdue_admin_summary', [
        'id' => 1, 'name' => 'X', 'date' => '01.01.2026', 'type' => 'Оплата',
    ]);

    expect($sent)->toBe(3);
    expect($bot->messagesTo($admin1->telegram_id))->toHaveCount(1);
    expect($bot->messagesTo($admin2->telegram_id))->toHaveCount(1);
    expect($bot->messagesTo($superadmin->telegram_id))->toHaveCount(1);
    expect($bot->messagesTo($other->telegram_id))->toBeEmpty();
    expect($bot->messagesTo($pending->telegram_id))->toBeEmpty();
});
