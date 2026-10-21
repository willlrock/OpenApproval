<?php

// Candidate checks use the candidate's own vendor, config and paths. No writes.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (($argv[1] ?? '') === '--url') {
    $url = rtrim(config('app.url'), '/');
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! filter_var($url, FILTER_VALIDATE_URL)) {
        fwrite(STDERR, "A production HTTPS APP_URL is required.\n");
        exit(1);
    }
    echo $url.'/health/ready';
    exit;
}
if (($argv[1] ?? '') === '--response') {
    $body = json_decode(stream_get_contents(STDIN), true);
    exit(is_array($body) && ($body['status'] ?? '') === 'ok'
        && ($body['release'] ?? '') === ($argv[2] ?? null) ? 0 : 1);
}
try {
    Illuminate\Support\Facades\DB::connection()->select('SELECT 1');
    if (in_array(config('queue.default'), ['sync', 'null'], true)
        || in_array(config('cache.default'), ['array', 'null'], true)
        || config('app.maintenance.driver') !== 'file') {
        throw new RuntimeException('Persistent queue/cache and file maintenance are required.');
    }
    if (! is_readable(public_path('build/manifest.json')) || ! is_writable(storage_path('logs'))
        || ! is_writable(storage_path('app/private')) || ! is_file(base_path('.release-sha'))) {
        throw new RuntimeException('Candidate paths are not ready.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Candidate database or storage check failed.\n");
    exit(1);
}
