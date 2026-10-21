<?php

// Exercise the actual dotenv preparation against an isolated fixture, never a server.
$repo = dirname(__DIR__, 2);
require $repo.'/vendor/autoload.php';
$base = $repo.'/storage/framework/testing';
if (! is_dir($base)) {
    mkdir($base, 0770, true);
}
$root = $base.'/production-config-'.bin2hex(random_bytes(8));
mkdir($root);
foreach (['vendor', 'etc'] as $directory) {
    mkdir($root.'/'.$directory);
}
file_put_contents($root.'/artisan', 'fixture');
file_put_contents($root.'/vendor/autoload.php', '<?php require '.var_export($repo.'/vendor/autoload.php', true).';');
$user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'fixture';
$group = function_exists('posix_getgrgid') ? posix_getgrgid(posix_getegid())['name'] : 'fixture';
$original = "APP_URL=https://approval.example.test\nAPP_KEY=fixture-app-key\nDB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=fixture\nDB_USERNAME=fixture\nDB_PASSWORD='fixture:password\\value'\nTELEGRAM_TOKEN=fixture-bot-token\n";
file_put_contents($root.'/.env', $original);
$run = function () use ($repo, $root, $user, $group): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, $repo.'/scripts/prepare-production.php', $root, 'php8.3-fpm.service', $root.'/etc', $user, $group],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, array_merge(getenv(), ['DEPLOY_LOCAL_PROXY' => '1']));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
};
$checks = 0;
$check = function (bool $condition, string $message) use (&$checks) {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
try {
    [$code, $output] = $run();
    $check($code === 0 && $output === '1', 'Initial configuration failed or printed unintended data.');
    $env = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    $check((bool) preg_match('/^[a-f0-9]{64}$/D', $env['TELEGRAM_WEBHOOK_SECRET']), 'Generated secret is invalid.');
    $check($env['TELEGRAM_WEBHOOK_URL'] === 'https://approval.example.test/telegram/webhook', 'Webhook endpoint is incorrect.');
    $check($env['APP_KEY'] === 'fixture-app-key' && $env['TELEGRAM_TOKEN'] === 'fixture-bot-token', 'Existing application credentials changed.');
    $check($env['SESSION_SECURE_COOKIE'] === 'true' && $env['TRUSTED_PROXIES'] === '127.0.0.1,::1', 'HTTPS/proxy configuration is incorrect.');
    $check(file_get_contents($root.'/etc/teg-approval-env-before-operations') === $original, 'Original dotenv was not retained.');
    $check(str_contains(file_get_contents($root.'/etc/teg-approval-backup.pgpass'), 'fixture\:password\\\\value'), 'PostgreSQL password was not escaped.');
    $before = file_get_contents($root.'/.env');
    [$code, $output] = $run();
    $check($code === 0 && $output === '1' && file_get_contents($root.'/.env') === $before, 'Preparation is not idempotent.');
    file_put_contents($root.'/etc/teg-approval-backup.env', "BACKUP_ROOT='/var/backups/alternative'\nBACKUP_KEEP_DAILY='14'\n");
    [$code] = $run();
    $backup = file_get_contents($root.'/etc/teg-approval-backup.env');
    $check($code === 0 && preg_match('/BACKUP_ROOT=[\'\"]\/var\/backups\/alternative[\'\"]/', $backup)
        && preg_match('/BACKUP_KEEP_DAILY=[\'\"]14[\'\"]/', $backup), 'Administrator backup overrides were overwritten.');
    file_put_contents($root.'/.env', $original."TELEGRAM_WEBHOOK_SECRET=invalid secret\n");
    [$code, $output] = $run();
    $check($code !== 0 && ! str_contains($output, 'fixture-bot-token') && ! str_contains($output, 'invalid secret'), 'Invalid secret was accepted or leaked.');
    $check(str_contains(file_get_contents($root.'/.env'), 'TELEGRAM_WEBHOOK_SECRET=invalid secret'), 'Invalid existing secret was silently replaced.');
    file_put_contents($root.'/.env', str_replace('https://approval.example.test', 'http://approval.example.test', $original));
    [$code] = $run();
    $check($code !== 0, 'Non-HTTPS application was accepted.');
    if (PHP_OS_FAMILY !== 'Windows') {
        $check((fileperms($root.'/etc/teg-approval-backup.pgpass') & 0777) === 0600, 'Credential file permissions are too broad.');
    }
    echo $checks." production configuration checks passed\n";
} finally {
    $resolved = realpath($root);
    $parent = realpath($base).DIRECTORY_SEPARATOR;
    if (! $resolved || strncasecmp($resolved, $parent, strlen($parent)) !== 0) {
        throw new RuntimeException('Unsafe fixture cleanup path.');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($resolved);
}
