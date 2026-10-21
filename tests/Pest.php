<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

// Keep the retired module's regression suite runnable through explicit opt-in.
pest()->beforeEach(function () {
    config(['tracker.enabled' => true]);
})->in('Feature/Tracker');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Deadline-pipeline test helpers
|--------------------------------------------------------------------------
*/

function seedRoles(): void
{
    foreach (['superadmin', 'admin', 'requester', 'approver', 'supplier', 'accountant', 'treasurer'] as $name) {
        \App\Models\Role::firstOrCreate(['name' => $name]);
    }
}

/**
 * Создаёт пользователя с конкретной ролью и telegram_id для проверки рассылок.
 */
function makeUserWithRole(string $roleName, array $attrs = []): \App\Models\User
{
    static $tg = 1_000_000;
    $tg++;

    $role = \App\Models\Role::where('name', $roleName)->firstOrFail();

    return \App\Models\User::create(array_merge([
        'telegram_id'    => $tg,
        'full_name'      => ucfirst($roleName) . ' User ' . $tg,
        'username'       => $roleName . $tg,
        'role_id'        => $role->id,
        'lang'           => 'ru',
        'account_status' => 'approved',
    ], $attrs));
}

/**
 * Подменяет Nutgram-бота через Mockery так, чтобы DeadlineNotifier мог его принять
 * по type-hint. Возвращает простой spy-объект с массивом messages — тесты ассертят на нём.
 */
function fakeBot(): object
{
    $spy = new class {
        public array $messages = [];
        public function messagesTo(int $chatId): array
        {
            return array_values(array_filter(
                $this->messages,
                fn ($m) => ($m['chat_id'] ?? null) === $chatId
            ));
        }
    };

    $mock = \Mockery::mock(\SergiX44\Nutgram\Nutgram::class);
    // Mockery теряет имена аргументов при `(...$args)` capture, поэтому
    // объявляем явно те, на которые мы реально ассертим в тестах.
    $mock->shouldReceive('sendMessage')
        ->andReturnUsing(function (
            $text = null,
            $chat_id = null,
            $parse_mode = null,
            $reply_markup = null,
        ) use ($spy) {
            $spy->messages[] = compact('text', 'chat_id', 'parse_mode', 'reply_markup');
        });

    app()->instance(\SergiX44\Nutgram\Nutgram::class, $mock);

    return $spy;
}

function makeProject(string $name = 'Test'): \App\Models\Project
{
    return \App\Models\Project::create(['name' => $name]);
}
