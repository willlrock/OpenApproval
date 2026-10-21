<?php

namespace App\Telegram\Conversations;

use App\Concerns\ConversationHelpers;
use App\Models\User;
use App\Services\SupplyWorkflow;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class CancelSupplyRequest extends Conversation
{
    use ConversationHelpers;

    public ?int $requestId = null;

    public ?int $revision = null;

    public function start(Nutgram $bot, ?int $requestId = null)
    {
        try {
            $actor = User::where('telegram_id', $bot->userId())->firstOrFail();
            $request = app(SupplyWorkflow::class)->pending((int) $requestId, $actor);
            $this->requestId = $request->id;
            $this->revision = $request->revision;
            $bot->sendMessage('Укажите причину отмены заявки #'.$request->id);
            $this->next('handleReason');
        } catch (\Throwable) {
            $bot->sendMessage('Заявка недоступна или уже обработана.');
            $this->end();
        }
    }

    public function handleReason(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $reason = $this->validateText($bot, $bot->message()?->text, 'Причина', 1000);
        if ($reason === null) {
            return;
        }
        try {
            abort_unless($this->revision, 409);
            app(SupplyWorkflow::class)->cancel($this->requestId, User::where('telegram_id', $bot->userId())->firstOrFail(), $reason, $this->revision);
            $bot->sendMessage('Заявка отменена.');
        } catch (\Throwable) {
            $bot->sendMessage('Заявка изменилась или доступ отозван. Откройте её заново.');
        }
        $this->end();
    }
}
