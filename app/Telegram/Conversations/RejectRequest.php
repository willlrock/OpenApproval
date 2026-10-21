<?php

namespace App\Telegram\Conversations;

use App\Concerns\ConversationHelpers;
use App\Helpers\BotLang;
use App\Models\ApprovalRequest;
use App\Models\User;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class RejectRequest extends Conversation
{
    use ConversationHelpers;

    public ?int $requestId = null;

    public int $revision = 1;

    public function start(Nutgram $bot, ?int $requestId = null, int $revision = 1)
    {
        $this->revision = $revision;
        if ($requestId) {
            $this->requestId = $requestId;
        }

        $lang = BotLang::getLang($bot);

        $telegramUser = User::where('telegram_id', $bot->userId())->first();
        if (! $telegramUser) {
            $bot->sendMessage(__('bot.rej_user_not_found', [], $lang));
            $this->end();

            return;
        }

        $request = ApprovalRequest::with('approvals')->find($this->requestId);
        if (! $request || $request->revision !== $this->revision || $request->status !== 'pending_approval') {
            $bot->sendMessage(__('bot.rej_request_not_found', [], $lang));
            $this->end();

            return;
        }

        $currentApproval = $request->approvals->where('level', $request->expected_approval_level)->first();
        if (! $currentApproval || $currentApproval->approver_id !== $telegramUser->id || $currentApproval->status !== 'pending') {
            $bot->sendMessage(__('bot.rej_cant_reject', [], $lang));
            $this->end();

            return;
        }

        $bot->sendMessage(
            __('bot.rej_prompt', ['id' => $this->requestId], $lang),
            parse_mode: 'HTML'
        );
        $this->next('handleComment');
    }

    public function handleComment(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $comment = $this->validateText($bot, $bot->message()?->text, 'Причина', 1000);
        if ($comment === null) {
            return;
        }
        try {
            app(\App\Services\ApprovalWorkflow::class)->reject($this->requestId, User::where('telegram_id', $bot->userId())->firstOrFail(), $comment, $this->revision);
            $bot->sendMessage('Заявка отклонена.');
        } catch (\Throwable) {
            $bot->sendMessage('Заявка изменилась или доступ отозван.');
        }
        $this->end();
    }
}
