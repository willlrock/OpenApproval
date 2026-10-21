<?php

namespace App\Telegram\Conversations;

use App\Concerns\ConversationHelpers;
use App\Helpers\BotLang;
use App\Models\TelegramBroadcast;
use App\Models\User;
use App\Services\TelegramBroadcastService;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BroadcastNotification extends Conversation
{
    use ConversationHelpers;

    public ?int $broadcastId = null;

    public function start(Nutgram $bot): void
    {
        if (! $this->actor($bot)) {
            return;
        }

        $lang = BotLang::getLang($bot);
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(__('bot.cancel_btn', [], $lang), callback_data: 'broadcast:cancel')
        );
        $bot->sendMessage(__('bot.adm_broadcast_prompt', [], $lang), reply_markup: $keyboard);
        $this->next('handleText');
    }

    public function handleText(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery() && $bot->callbackQuery()->data === 'broadcast:cancel') {
            $bot->answerCallbackQuery();
            $bot->sendMessage(__('bot.op_cancelled', [], BotLang::getLang($bot)));
            $this->end();

            return;
        }
        if ($this->isCancelled($bot)) {
            return;
        }
        $actor = $this->actor($bot);
        if (! $actor) {
            return;
        }

        $lang = $actor->lang ?? 'ru';
        $text = $this->validateText($bot, $bot->message()?->text, __('bot.adm_broadcast_field', [], $lang), 4096);
        if ($text === null) {
            return;
        }

        $broadcast = app(TelegramBroadcastService::class)->createDraft($actor, $text);
        $this->broadcastId = $broadcast->id;
        if (count($broadcast->recipients) === 0) {
            $broadcast->update(['status' => 'cancelled']);
            $bot->sendMessage(__('bot.adm_broadcast_no_recipients', [], $lang));
            $this->end();

            return;
        }

        // The preview uses the identical text and formatting as delivery.
        $bot->sendMessage(__('bot.adm_broadcast_preview', [], $lang));
        $bot->sendMessage(app(\App\Services\TelegramNotificationMessage::class)->single($broadcast->text), parse_mode: 'HTML');
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(__('bot.adm_broadcast_send', [], $lang), callback_data: 'broadcast:send:'.$broadcast->id),
            InlineKeyboardButton::make(__('bot.cancel_btn', [], $lang), callback_data: 'broadcast:cancel:'.$broadcast->id)
        );
        $bot->sendMessage(__('bot.adm_broadcast_confirm', ['count' => count($broadcast->recipients)], $lang), reply_markup: $keyboard);
        $this->next('handleConfirmation');
    }

    public function handleConfirmation(Nutgram $bot): void
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $actor = $this->actor($bot);
        if (! $actor || ! $bot->isCallbackQuery()) {
            return;
        }

        $data = $bot->callbackQuery()->data;
        if (! in_array($data, ['broadcast:send:'.$this->broadcastId, 'broadcast:cancel:'.$this->broadcastId], true)) {
            $bot->answerCallbackQuery(text: __('bot.cb_session_expired_full', [], $actor->lang ?? 'ru'));

            return;
        }
        $bot->answerCallbackQuery();

        if ($data === 'broadcast:cancel:'.$this->broadcastId) {
            $bot->sendMessage(__('bot.op_cancelled', [], $actor->lang ?? 'ru'));
            $this->end();

            return;
        }

        $queued = app(TelegramBroadcastService::class)->queue($actor, $this->broadcastId);
        // End the dialog as soon as the job is persisted, before Telegram I/O.
        $this->end();
        try {
            $bot->editMessageReplyMarkup(reply_markup: null);
        } catch (\Exception) {
        }
        $bot->sendMessage(__('bot.'.($queued ? 'adm_broadcast_queued' : 'adm_broadcast_already_queued'), [], $actor->lang ?? 'ru'));
    }

    protected function closing(Nutgram $bot): void
    {
        if ($this->broadcastId) {
            TelegramBroadcast::whereKey($this->broadcastId)->where('status', 'draft')->update(['status' => 'cancelled']);
        }
    }

    private function actor(Nutgram $bot): ?User
    {
        $actor = User::where('telegram_id', $bot->userId())->first();
        if ($actor?->isApproved() && $actor->hasRole(['admin', 'superadmin'])) {
            return $actor;
        }

        $bot->sendMessage(__('bot.adm_no_access', [], $actor->lang ?? 'ru'));
        $this->end();

        return null;
    }
}
