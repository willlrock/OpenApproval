<?php

use App\Services\SystemLogReader;

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
    seedRoles();
    $this->withoutVite();
});

test('system logs are restricted to administrators', function (string $role, int $status) {
    $mock = Mockery::mock(SystemLogReader::class);
    if ($status === 200) {
        $mock->shouldReceive('readConfigured')->once()->with(config('logging'), [], Mockery::type('array'))->andReturn(['entries' => [], 'unavailable' => false, 'limited' => false]);
    } else {
        $mock->shouldNotReceive('readConfigured');
    }
    app()->instance(SystemLogReader::class, $mock);
    $response = $this->actingAs(makeUserWithRole($role))->get(route('system-logs'));
    $response->assertStatus($status);
    if ($status === 200) {
        $response->assertInertia(fn ($page) => $page->component('Dashboard/SystemLogs')->has('entries', 0));
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
})->with([['admin', 200], ['superadmin', 200], ['requester', 403], ['approver', 403], ['supplier', 403], ['accountant', 403], ['treasurer', 403]]);

test('guests cannot read system logs', function () {
    $this->get(route('system-logs'))->assertRedirect(route('login'));
});

test('system log filters are validated', function () {
    $this->actingAs(makeUserWithRole('admin'))->getJson(route('system-logs', ['level' => 'INVALID', 'date' => 'wrong', 'search' => ['nested']]))
        ->assertUnprocessable()->assertJsonValidationErrors(['level', 'date', 'search']);
});

test('system logs display the daily file written by the configured logger', function () {
    $directory = sys_get_temp_dir().'/system-logs-'.bin2hex(random_bytes(8));
    mkdir($directory);
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['daily'],
        'logging.channels.daily.path' => $directory.'/configured.log',
    ]);
    $logger = new Illuminate\Log\LogManager(app());
    try {
        $logger->error('System log integration password=hidden-credential');
        $logger->channel()->getLogger()->close();
        $this->actingAs(makeUserWithRole('admin'))->get(route('system-logs', ['level' => 'ERROR', 'search' => 'integration']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/SystemLogs')
                ->where('unavailable', false)
                ->has('entries', 1)
                ->where('entries.0.level', 'ERROR')
                ->where('entries.0.message', fn ($message) => str_starts_with($message, 'System log integration password=[СКРЫТО]')));
    } finally {
        $logger->channel()->getLogger()->close();
        // Request completion logging may open the same daily file via the facade.
        Illuminate\Support\Facades\Log::channel()->getLogger()->close();
        Illuminate\Support\Facades\Log::forgetChannel();
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});
