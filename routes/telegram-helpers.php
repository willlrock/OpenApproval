<?php

use App\Models\User;
use SergiX44\Nutgram\Nutgram;

function checkUserAccess(Nutgram $bot): ?User
{
    $user = User::where('telegram_id', $bot->userId())->first();

    if (! $user) {
        return null;
    }

    $lang = $user->lang ?? 'ru';

    if ($user->isPending()) {
        $bot->sendMessage(__('bot.cb_pending', [], $lang), parse_mode: 'HTML');

        return null;
    }

    if ($user->isRejected()) {
        $bot->sendMessage(__('bot.cb_rejected_reg', [], $lang), parse_mode: 'HTML');

        return null;
    }

    return $user;
}

/**
 * Complete one level of a management document. The database changes are
 * transactional: if signature capture or final PDF assembly fails, the
 * approval remains pending and the user can safely retry.
 */
function finalizeManagementDocumentApproval(Nutgram $bot, int $requestId, User $telegramUser, ?string $comment): void
{
    finalizeApproval($bot, $requestId, $comment);
}

/**
 * Finalize an approve action after the optional-comment step. Marks the current
 * approval row as approved (with optional comment), advances to the next level
 * if any, otherwise transitions the request to 'approved' and runs the full
 * post-approval workflow (PDF + Sheets + financial notifications).
 *
 * Called by both `approve_skip:{id}` (no comment) and the ApproveComment
 * conversation (with comment). Re-validates state defensively.
 */
function finalizeApproval(Nutgram $bot, int $requestId, ?string $comment, int $revision = 1): void
{
    $user = User::where('telegram_id', $bot->userId())->first();
    if (! $user) {
        return;
    }
    try {
        [$request] = app(\App\Services\ApprovalWorkflow::class)->approve($requestId, $user, $comment, $revision);
    } catch (\Throwable) {
        $bot->sendMessage('Заявка изменилась, уже обработана или доступ отозван.');

        return;
    }
    $bot->sendMessage('Решение сохранено по заявке #'.$request->id.'. Уведомления будут доставлены автоматически.');
}
