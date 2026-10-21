<?php

use App\Services\SystemLogReader;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'system-log-test');
    $this->directory = $this->path.'-logs';
    mkdir($this->directory);
    $this->logging = [
        'default' => 'stack',
        'channels' => [
            'stack' => ['driver' => 'stack', 'channels' => ['stderr', 'daily']],
            'stderr' => ['driver' => 'monolog', 'handler_with' => ['stream' => 'php://stderr']],
            'daily' => ['driver' => 'daily', 'path' => $this->directory.'/application.log'],
        ],
    ];
});
afterEach(function () {
    unlink($this->path);
    foreach (glob($this->directory.'/*') as $path) {
        unlink($path);
    }
    rmdir($this->directory);
});

test('reads multiline entries newest first and filters date level and Unicode text', function () {
    file_put_contents($this->path, "[2026-09-07 10:00:00] production.INFO: old\n[2026-09-08 10:00:00] production.ERROR: Ошибка\n[stacktrace]\n#0 example\n[2026-09-08 11:00:00] production.WARNING: latest\n");
    $reader = new SystemLogReader;
    expect($reader->read($this->path)['entries'][0]['message'])->toBe('latest');
    $result = $reader->read($this->path, ['date' => '2026-09-08', 'level' => 'ERROR', 'search' => 'ошибка']);
    expect($result['entries'])->toHaveCount(1);
    expect($result['entries'][0]['details'])->toContain('#0 example');
});

test('redacts credentials before returning summaries or details', function () {
    file_put_contents($this->path, '[2026-09-08 10:00:00] production.ERROR: {"password":"a secret phrase", "api_key":"hidden-key"} Bearer abc.def secret-config https://user:pass@example.com 123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ123456789');
    $entry = (new SystemLogReader)->read($this->path, [], ['secret-config'])['entries'][0];
    foreach (['a secret phrase', 'hidden-key', 'abc.def', 'secret-config', 'user:pass', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'] as $secret) {
        expect($entry['details'])->not->toContain($secret);
        expect($entry['message'])->not->toContain($secret);
    }
});

test('bounds large files and returned entry count and handles missing files', function () {
    file_put_contents($this->path, str_repeat('x', 1100000)."\n".str_repeat("[2026-09-08 10:00:00] production.ERROR: example\n", 250));
    $result = (new SystemLogReader)->read($this->path);
    expect($result['limited'])->toBeTrue()->and($result['entries'])->toHaveCount(200);
    expect((new SystemLogReader)->read($this->path.'-missing')['unavailable'])->toBeTrue();
});

test('reads rotated logs in the active stack and searches the selected day', function () {
    file_put_contents($this->directory.'/application-2026-10-19.log', "[2026-10-19 23:59:00] production.ERROR: older secret-config\n");
    file_put_contents($this->directory.'/application-2026-10-20.log', "[2026-10-20 00:01:00] production.WARNING: newer\n");
    file_put_contents($this->directory.'/unrelated-2026-10-20.log', "[2026-10-20 00:02:00] production.ERROR: unrelated\n");
    $reader = new SystemLogReader;
    $result = $reader->readConfigured($this->logging, [], ['secret-config']);
    expect($result['unavailable'])->toBeFalse()
        ->and(array_column($result['entries'], 'message'))->toBe(['newer', 'older [СКРЫТО]']);
    $result = $reader->readConfigured($this->logging, ['date' => '2026-10-19', 'level' => 'ERROR', 'search' => 'older']);
    expect($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]['date'])->toBe('2026-10-19 23:59:00');
});

test('empty configured file logs are available before the first write', function () {
    $reader = new SystemLogReader;
    expect($reader->readConfigured($this->logging))->toBe(['entries' => [], 'unavailable' => false, 'limited' => false]);
    $this->logging['default'] = 'stderr';
    expect($reader->readConfigured($this->logging)['unavailable'])->toBeTrue();
    $this->logging['default'] = 'daily';
    $this->logging['channels']['daily']['path'] = $this->directory.'/missing/application.log';
    expect($reader->readConfigured($this->logging)['unavailable'])->toBeTrue();
});

test('resolves nested stacks without cycles and uses the configured single path', function () {
    file_put_contents($this->path, "[2026-10-20 12:00:00] production.INFO: configured\n");
    $this->logging['channels']['stack']['channels'] = ['nested', 'single'];
    $this->logging['channels']['nested'] = ['driver' => 'stack', 'channels' => ['stack', 'single']];
    $this->logging['channels']['single'] = ['driver' => 'single', 'path' => $this->path];
    expect((new SystemLogReader)->readConfigured($this->logging)['entries'])->toHaveCount(1);
});

test('bounds bytes and entry count across multiple rotated files', function () {
    file_put_contents($this->directory.'/application-2026-10-19.log', str_repeat("[2026-10-19 12:00:00] production.INFO: older\n", 150));
    file_put_contents($this->directory.'/application-2026-10-20.log', str_repeat("[2026-10-20 12:00:00] production.INFO: newer\n", 150));
    $reader = new SystemLogReader;
    $result = $reader->readConfigured($this->logging);
    expect($result['limited'])->toBeTrue()->and($result['entries'])->toHaveCount(200);
    file_put_contents($this->directory.'/application-2026-10-20.log', str_repeat('x', 1100000)."\n[2026-10-20 12:00:00] production.INFO: latest\n");
    clearstatcache();
    $result = $reader->readConfigured($this->logging);
    expect($result['limited'])->toBeTrue()
        ->and(array_column($result['entries'], 'message'))->toBe(['latest']);
});
