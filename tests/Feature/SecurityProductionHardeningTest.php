<?php

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    Storage::fake('public');
    Storage::fake('local');
});

test('operational migration preserves independently deployed account link fields', function () {
    $user = makeUserWithRole('requester', ['telegram_id' => null]);
    $token = app(App\Services\TelegramAccountLink::class)->issue($user);
    $migration = require database_path('migrations/2026_10_02_000001_harden_account_links_and_request_queries.php');
    $migration->down();
    expect($user->fresh()->telegram_link_token_hash)->toBe(hash('sha256', $token));
    $migration->up();
    app(App\Services\TelegramAccountLink::class)->consume($token, 123456, 'linked');
    expect($user->fresh()->telegram_id)->toBe(123456)
        ->and(\Illuminate\Support\Facades\Schema::hasTable('operational_heartbeats'))->toBeTrue();
});

test('webhook rejects absent and invalid secrets and passes only the configured header', function () {
    config(['nutgram.webhook_secret' => str_repeat('a', 48)]);
    $this->postJson('/telegram/webhook', [])->assertForbidden();
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('b', 48))->postJson('/telegram/webhook', [])->assertForbidden();
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('run')->once();
    app()->instance(Nutgram::class, $bot);
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('a', 48))->postJson('/telegram/webhook', [])->assertNoContent();
    $this->postJson('/123456:legacy-token-path', [])->assertNotFound();
});

test('webhook setup uses the fixed endpoint and the same independent secret', function () {
    config(['app.url' => 'https://approval.example.test', 'nutgram.webhook_secret' => str_repeat('s', 48)]);
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('setWebhook')->once()->withArgs(fn (...$args) => ($args['url'] ?? $args[0] ?? null) === 'https://approval.example.test/telegram/webhook'
        && in_array(str_repeat('s', 48), $args, true))->andReturn(true);
    $bot->shouldReceive('getWebhookInfo')->andReturnUsing(function () {
        $info = new \SergiX44\Nutgram\Telegram\Types\Common\WebhookInfo;
        $info->url = 'https://approval.example.test/telegram/webhook';

        return $info;
    });
    app()->instance(Nutgram::class, $bot);
    $this->artisan('telegram:webhook', ['action' => 'set'])->assertExitCode(0);
});

test('readiness detects a stopped queue or scheduler without exposing internals', function () {
    config(['queue.default' => 'database']);
    $this->getJson(route('health.ready'))->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    DB::table('operational_heartbeats')->insert(['component' => 'scheduler', 'last_seen_at' => now()]);
    (new RecordQueueHeartbeat)->handle();
    $this->getJson(route('health.ready'))->assertOk()->assertExactJson(['status' => 'ok']);
    config(['queue.default' => 'sync']);
    $this->getJson(route('health.ready'))->assertStatus(503);
    config(['queue.default' => 'database']);
    $this->travel(6)->minutes();
    $this->getJson(route('health.ready'))->assertStatus(503);
});

test('forwarded headers are ignored unless the direct peer is explicitly trusted', function () {
    $middleware = new \App\Http\Middleware\TrustConfiguredProxies;
    $probe = function (array $trusted) use ($middleware) {
        config(['app.trusted_proxies' => $trusted]);
        $request = \Illuminate\Http\Request::create('http://approval.example.test/health/ready', server: [
            'REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.15', 'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        return $middleware->handle($request, fn ($r) => response()->json(['ip' => $r->ip(), 'secure' => $r->isSecure()]))->getData(true);
    };
    expect($probe(['*']))->toBe(['ip' => '192.0.2.10', 'secure' => false]);
    expect($probe(['192.0.2.10']))->toBe(['ip' => '203.0.113.15', 'secure' => true]);
});
