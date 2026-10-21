<?php

namespace App\Console\Commands;

use App\Telegram\Menu;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommandScopeAllPrivateChats;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommandScopeDefault;

class SetBotCommands extends Command
{
    protected $signature   = 'bot:set-commands';
    protected $description = 'Зарегистрировать команды бота в Telegram (кнопка меню слева)';

    public function handle(Nutgram $bot): int
    {
        $userCommands  = $this->buildCommands(Menu::commands(isAdmin: false));
        $adminCommands = $this->buildCommands(Menu::commands(isAdmin: true));

        // Для всех пользователей по умолчанию
        $bot->setMyCommands(
            commands: $userCommands,
            scope: new BotCommandScopeDefault()
        );

        // Для всех личных чатов (включая админов)
        $bot->setMyCommands(
            commands: $adminCommands,
            scope: new BotCommandScopeAllPrivateChats()
        );

        $this->info('✅ Команды бота успешно зарегистрированы в Telegram!');
        $this->table(
            ['Команда', 'Описание'],
            array_map(fn($c) => ['/' . $c['command'], $c['description']], Menu::commands(isAdmin: true))
        );

        return self::SUCCESS;
    }

    private function buildCommands(array $items): array
    {
        return array_map(fn($c) => [
            'command'     => $c['command'],
            'description' => $c['description'],
        ], $items);
    }
}
