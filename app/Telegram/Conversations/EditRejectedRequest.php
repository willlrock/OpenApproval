<?php

namespace App\Telegram\Conversations;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Policies\ApprovalRequestPolicy;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class EditRejectedRequest extends Conversation
{
    public ?int $requestId = null;

    public function start(Nutgram $bot, ?int $requestId = null)
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        $request = ApprovalRequest::find($requestId ?? $this->requestId);
        if (! $user || ! $request || ! app(ApprovalRequestPolicy::class)->revise($user, $request)) {
            $bot->sendMessage('Исправление этой заявки недоступно. Для нового финансового этапа создайте новую заявку.');
        } else {
            $bot->sendMessage('Исправьте заявку с сохранением номера, вложений и истории: '.route('request-submissions.show', $request->id));
        }
        $this->end();
    }
}
