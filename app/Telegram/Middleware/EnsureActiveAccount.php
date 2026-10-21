<?php

namespace App\Telegram\Middleware;

use App\Models\User;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

class EnsureActiveAccount
{
    public function __invoke(Nutgram $bot, $next): void
    {
        if ($bot->userId() === null) {
            $next($bot);

            return;
        }

        $user = User::where('telegram_id', $bot->userId())->first();
        if ($user && ! $user->is_active) {
            if ($bot->isCallbackQuery()) {
                $bot->answerCallbackQuery(text: __('bot.account_deactivated', [], $user->lang ?? 'ru'), show_alert: true);
            } else {
                $bot->sendMessage(__('bot.account_deactivated', [], $user->lang ?? 'ru'), reply_markup: ReplyKeyboardRemove::make(true));
            }

            return;
        }

        $next($bot);
    }
}
