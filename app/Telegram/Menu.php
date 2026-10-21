<?php

namespace App\Telegram;

use App\Models\User;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

class Menu
{
    /**
     * Main menu (persistent bottom keyboard), language-aware.
     */
    public static function main(User $user): ReplyKeyboardMarkup
    {
        $lang    = $user->lang ?? 'ru';
        $isAdmin = in_array($user->role, ['admin', 'superadmin']);

        $keyboard = ReplyKeyboardMarkup::make(resize_keyboard: true);

        $keyboard->addRow(
            KeyboardButton::make(__('bot.menu_expense', [], $lang)),
            KeyboardButton::make(__('bot.menu_supply', [], $lang))
        );

        $keyboard->addRow(
            KeyboardButton::make(__('bot.menu_my_requests', [], $lang)),
            KeyboardButton::make(__('bot.menu_stats', [], $lang))
        );

        $keyboard->addRow(
            KeyboardButton::make(__('bot.menu_expense_pdf', [], $lang)),
            KeyboardButton::make(__('bot.menu_management_document', [], $lang))
        );

        // Approvers (including admin/superadmin who can also be approvers)
        $isApprover = in_array($user->role, ['approver', 'admin', 'superadmin']);
        if ($isApprover) {
            $keyboard->addRow(
                KeyboardButton::make(__('bot.menu_my_approvals', [], $lang))
            );
        }

        if ($isAdmin) {
            $keyboard->addRow(
                KeyboardButton::make(__('bot.menu_admin', [], $lang))
            );
        }

        if ($user->isAccountant() || $user->isTreasurer()) {
            $keyboard->addRow(
                KeyboardButton::make(__('bot.menu_finance', [], $lang))
            );
        }

        return $keyboard;
    }

    /**
     * Remove keyboard.
     */
    public static function remove(): ReplyKeyboardRemove
    {
        return ReplyKeyboardRemove::make(true);
    }

    /**
     * Commands for BotCommand menu (left of input field), language-aware.
     */
    public static function commands(bool $isAdmin = false, string $lang = 'ru'): array
    {
        $commands = [
            ['command' => 'start',      'description' => __('bot.cmd_start', [], $lang)],
            ['command' => 'approval',   'description' => __('bot.cmd_approval', [], $lang)],
            ['command' => 'approvalpdf', 'description' => __('bot.cmd_approval_pdf', [], $lang)],
            ['command' => 'document',   'description' => __('bot.cmd_document', [], $lang)],
            ['command' => 'supply',     'description' => __('bot.cmd_supply', [], $lang)],
            ['command' => 'myrequests', 'description' => __('bot.cmd_myrequests', [], $lang)],
            ['command' => 'stats',      'description' => __('bot.cmd_stats', [], $lang)],
            ['command' => 'cancel',     'description' => __('bot.cmd_cancel', [], $lang)],
            ['command' => 'help',       'description' => __('bot.cmd_help', [], $lang)],
        ];

        if ($isAdmin) {
            $commands[] = ['command' => 'admin', 'description' => __('bot.cmd_admin', [], $lang)];
        }

        return $commands;
    }
}
