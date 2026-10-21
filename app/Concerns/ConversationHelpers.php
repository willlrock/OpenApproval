<?php

namespace App\Concerns;

use App\Helpers\BotLang;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

trait ConversationHelpers
{
    /**
     * Checks for /cancel or ❌ Cancel button at any point.
     * Also lets the main-menu reply buttons break out of an active conversation —
     * otherwise a stale dialog swallows them and the menu looks "dead".
     */
    protected function isCancelled(Nutgram $bot): bool
    {
        $raw = trim($bot->message()?->text ?? '');
        if ($raw === '') return false;

        $text = mb_strtolower($raw);
        $cancelWords = ['/cancel', 'отмена', '/отмена', '❌ отмена', 'cancel', '❌ cancel', '❌ cancel operation'];
        if (in_array($text, $cancelWords)) {
            $user = User::where('telegram_id', $bot->userId())->first();
            $bot->sendMessage(
                __('bot.op_cancelled', [], BotLang::getLang($bot)),
                reply_markup: $user?->isApproved() ? \App\Telegram\Menu::main($user) : \App\Telegram\Menu::remove()
            );
            $this->end();
            return true;
        }

        return $this->escapeToMenuAction($bot, $text);
    }

    /**
     * If the incoming text is one of the main-menu buttons, end the current
     * conversation and start the corresponding one.
     */
    protected function escapeToMenuAction(Nutgram $bot, string $lowerText): bool
    {
        $menuActions = [
            'menu_expense'      => \App\Telegram\Conversations\CreateApprovalRequest::class,
            'menu_expense_pdf'  => \App\Telegram\Conversations\CreateApprovalPdfRequest::class,
            'menu_supply'       => \App\Telegram\Conversations\CreateSupplyRequest::class,
            'menu_my_requests'  => \App\Telegram\Conversations\MyRequests::class,
            'menu_stats'        => \App\Telegram\Conversations\ViewStats::class,
            'menu_my_approvals' => \App\Telegram\Conversations\MyApprovals::class,
            'menu_admin'        => \App\Telegram\Conversations\AdminPanel::class,
            'menu_finance'      => \App\Telegram\Conversations\FinancePanel::class,
        ];

        foreach (['ru', 'en'] as $l) {
            foreach ($menuActions as $key => $class) {
                if ($lowerText !== mb_strtolower(__("bot.{$key}", [], $l))) continue;

                if ($class === \App\Telegram\Conversations\AdminPanel::class) {
                    $user = User::where('telegram_id', $bot->userId())->first();
                    if (!in_array($user?->role, ['admin', 'superadmin', 'supplier'])) return false;
                }

                $this->end();
                $class::begin($bot);
                return true;
            }
        }

        return false;
    }

    /**
     * Atomically claims a one-shot action tied to the tapped inline message, so a
     * double-tap (or a retried webhook) cannot run the same final action twice.
     * Returns false when the action was already claimed.
     */
    protected function claimCallbackOnce(Nutgram $bot, string $action, int $ttl = 600): bool
    {
        $msgId = $bot->callbackQuery()?->message?->message_id ?? 0;
        return Cache::add("once:{$action}:{$bot->userId()}:{$msgId}", 1, $ttl);
    }

    /**
     * Releases a claim taken by claimCallbackOnce() — call when the action failed
     * and the user should be able to press the button again.
     */
    protected function releaseCallbackClaim(Nutgram $bot, string $action): void
    {
        $msgId = $bot->callbackQuery()?->message?->message_id ?? 0;
        Cache::forget("once:{$action}:{$bot->userId()}:{$msgId}");
    }

    /**
     * Validate a text input: must be non-empty and within length limits.
     */
    protected function validateText(Nutgram $bot, ?string $text, string $fieldLabel, int $maxLength = 500): ?string
    {
        $lang = BotLang::getLang($bot);
        if (!$text || trim($text) === '') {
            $bot->sendMessage(__('bot.enter_field_text', ['field' => $fieldLabel], $lang));
            return null;
        }

        $text = trim($text);

        if (mb_strlen($text) > $maxLength) {
            $bot->sendMessage(__('bot.field_too_long', ['field' => $fieldLabel, 'max' => $maxLength], $lang));
            return null;
        }

        return $text;
    }

    /**
     * Validate a positive numeric input.
     */
    protected function validatePositiveNumber(Nutgram $bot, ?string $text, string $fieldLabel, bool $integerOnly = false): float|int|null
    {
        $lang = BotLang::getLang($bot);
        if (!$text) {
            $bot->sendMessage(__('bot.enter_field_number', ['field' => $fieldLabel], $lang));
            return null;
        }

        $value = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim($text));

        if (!is_numeric($value) || (float) $value <= 0) {
            $bot->sendMessage(__('bot.invalid_number_value', ['value' => $text], $lang));
            return null;
        }

        if ($integerOnly) {
            if ((float) $value !== floor((float) $value)) {
                $bot->sendMessage(__('bot.enter_integer_only', [], $lang));
                return null;
            }
            return (int) $value;
        }

        return (float) $value;
    }

    /**
     * Inline cancel button — can be attached to any message during an operation.
     */
    protected function cancelButton(Nutgram $bot): InlineKeyboardMarkup
    {
        $lang = BotLang::getLang($bot);
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    __('bot.cancel_btn', [], $lang),
                    callback_data: 'global:cancel'
                )
            );
    }

    /**
     * Adds the cancel button to an existing InlineKeyboard.
     */
    protected function withCancelButton(
        InlineKeyboardMarkup $keyboard,
        Nutgram $bot
    ): InlineKeyboardMarkup {
        $lang = BotLang::getLang($bot);
        return $keyboard->addRow(
            InlineKeyboardButton::make(
                __('bot.cancel_btn', [], $lang),
                callback_data: 'global:cancel'
            )
        );
    }

    protected function sendMenu(Nutgram $bot): void
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        if ($user?->isApproved()) {
            $bot->sendMessage(
                __('bot.main_menu_label', [], BotLang::getLang($bot)),
                reply_markup: \App\Telegram\Menu::main($user)
            );
        }
    }
}
