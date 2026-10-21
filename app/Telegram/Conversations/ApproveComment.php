<?php

namespace App\Telegram\Conversations;

use App\Helpers\BotLang;
use App\Models\User;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use App\Concerns\ConversationHelpers;

class ApproveComment extends Conversation
{
    use ConversationHelpers;

    public ?int $requestId = null;
    public int $revision = 1;

    public function start(Nutgram $bot, ?int $requestId = null, int $revision = 1)
    {
        $this->revision = $revision;
        $lang = BotLang::getLang($bot);

        if (!$requestId) {
            $this->end();
            return;
        }

        $this->requestId = $requestId;

        $bot->sendMessage(
            __('bot.cb_send_comment', ['id' => $requestId], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );

        $this->next('handleComment');
    }

    public function handleComment(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        // Inline cancel button → finalize WITHOUT a comment (user changed their mind about commenting, not about approving).
        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data ?? '';
            if ($data === 'global:cancel') {
                try { $bot->answerCallbackQuery(); } catch (\Exception) {}
                try { $bot->editMessageReplyMarkup(reply_markup: null); } catch (\Exception) {}
                finalizeApproval($bot, (int) $this->requestId, null, $this->revision);
                $this->sendMenu($bot);
                $this->end();
                return;
            }
            return;
        }

        // Treat /cancel and similar as "skip comment, but still approve".
        $text = trim($bot->message()?->text ?? '');
        $cancelWords = ['/cancel', 'отмена', '/отмена', '❌ отмена', 'cancel', '❌ cancel'];
        if (in_array(mb_strtolower($text), $cancelWords, true)) {
            finalizeApproval($bot, (int) $this->requestId, null, $this->revision);
            $this->sendMenu($bot);
            $this->end();
            return;
        }

        if ($text === '') {
            $bot->sendMessage(__('bot.cb_send_comment_empty', [], $lang));
            return;
        }

        // Cap at 1000 chars to avoid runaway long comments
        $comment = mb_substr($text, 0, 1000);

        $bot->sendMessage(__('bot.cb_comment_saved', [], $lang), parse_mode: 'HTML');

        finalizeApproval($bot, (int) $this->requestId, $comment, $this->revision);
        $this->sendMenu($bot);
        $this->end();
    }
}
