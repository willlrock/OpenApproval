<?php

namespace App\Services;

class TelegramNotificationMessage
{
    /** Single-message uses (broadcasts) already validate the visible text length. */
    public function single(string $text): string
    {
        return implode('', $this->pages($text));
    }

    public function sendTranslated(\SergiX44\Nutgram\Nutgram $bot, string $key, array $params, string $lang, int|string $chatId, ?\SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup $keyboard = null): void
    {
        $pages = $this->translatedPages($key, $params, $lang);
        foreach ($pages as $index => $page) {
            $bot->sendMessage($page, chat_id: $chatId, parse_mode: 'HTML', reply_markup: $index === array_key_last($pages) ? $keyboard : null);
        }
    }

    /** Escape user content and keep every page valid HTML within Telegram's limits. */
    public function pages(string $text): array
    {
        $pages = [];
        $page = '';
        foreach (explode("\n", $text) as $index => $line) {
            $prefix = $index === 0 ? '' : "\n";
            foreach (mb_str_split($line === '' ? ' ' : $line, 200) as $chunkIndex => $chunk) {
                $escaped = htmlspecialchars($line === '' ? '' : $chunk, ENT_QUOTES, 'UTF-8');
                if ($index === 0) {
                    $escaped = '<b>'.$escaped.'</b>';
                } elseif ($chunkIndex === 0) {
                    $escaped = preg_replace('/^([^:]{1,60}:)(?=\s|$)/u', '<b>$1</b>', $escaped);
                }
                $fragment = ($chunkIndex === 0 ? $prefix : '').$escaped;
                if ($page !== '' && mb_strlen($page) + mb_strlen($fragment) > 2000) {
                    $pages[] = $page;
                    $page = '';
                }
                $page .= $fragment;
            }
        }
        if ($page !== '') {
            $pages[] = $page;
        }

        return $pages;
    }

    /** Translate the trusted template before inserting untrusted parameter values. */
    public function translatedPages(string $key, array $params, string $lang): array
    {
        $template = strip_tags(__($key, [], $lang));
        $replace = [];
        foreach ($params as $name => $value) {
            $replace[':'.$name] = (string) $value;
        }

        return $this->pages(strtr($template, $replace));
    }
}
