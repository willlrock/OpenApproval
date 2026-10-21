<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Nutgram;

class CreateApprovalPdfRequest extends CreateApprovalRequest
{
    public function start(Nutgram $bot, ?string $mode = null)
    {
        parent::start($bot, 'pdf');
    }
}
