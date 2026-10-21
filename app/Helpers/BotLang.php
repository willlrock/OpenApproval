<?php

namespace App\Helpers;

use App\Models\User;
use SergiX44\Nutgram\Nutgram;

class BotLang
{
    /**
     * Get the language for a Telegram user (from DB).
     */
    public static function getLang(Nutgram $bot): string
    {
        $user = User::where('telegram_id', $bot->userId())->value('lang');
        return $user ?? 'ru';
    }

    /**
     * Translate a bot message key for a given Nutgram bot instance.
     */
    public static function t(Nutgram $bot, string $key, array $replace = []): string
    {
        $lang = self::getLang($bot);
        return __($key, $replace, $lang);
    }

    /**
     * Translate with explicit language string.
     */
    public static function tl(string $lang, string $key, array $replace = []): string
    {
        return __($key, $replace, $lang);
    }
}
