<?php

// Run by the root deployment process. Values from dotenv are never executed or logged.
$prepare = function () use ($argv) {
    $app = realpath($argv[1] ?? '');
    $service = $argv[2] ?? '';
    $configDirectory = $argv[3] ?? '/etc';
    $appUser = $argv[4] ?? 'tegbot';
    $appGroup = $argv[5] ?? 'www-data';
    if (! $app || ! is_file($app.'/artisan') || ! preg_match('/^php[0-9]+\.[0-9]+-fpm\.service$/D', $service)) {
        throw new RuntimeException('Invalid application directory or PHP-FPM service.');
    }
    require $app.'/vendor/autoload.php';
    $path = $app.'/.env';
    if (is_link($path) || ! is_file($path)) {
        throw new RuntimeException('A regular application .env file is required.');
    }
    $source = file_get_contents($path);
    $env = Dotenv\Dotenv::parse($source);
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        if (! isset($env[$key]) || $env[$key] === '' || preg_match('/[\r\n\x00]/', $env[$key])) {
            throw new RuntimeException('Configure '.$key.' before deployment.');
        }
    }
    if (($env['DB_CONNECTION'] ?? '') !== 'pgsql') {
        throw new RuntimeException('Native production backups require PostgreSQL.');
    }
    $url = rtrim($env['APP_URL'] ?? '', '/');
    if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
        || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
        || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
        throw new RuntimeException('An HTTPS APP_URL is required.');
    }
    $botEnabled = ! empty($env['TELEGRAM_TOKEN']);
    $changes = ['SESSION_SECURE_COOKIE' => 'true'];
    if ($botEnabled) {
        $secret = $env['TELEGRAM_WEBHOOK_SECRET'] ?? '';
        if ($secret !== '' && ! preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret)) {
            throw new RuntimeException('Existing webhook secret is invalid; it was not replaced.');
        }
        $changes['TELEGRAM_WEBHOOK_SECRET'] = $secret ?: bin2hex(random_bytes(32));
        $changes['TELEGRAM_WEBHOOK_URL'] = $url.'/telegram/webhook';
    }
    if (empty($env['TRUSTED_PROXIES']) && getenv('DEPLOY_LOCAL_PROXY') === '1') {
        $changes['TRUSTED_PROXIES'] = '127.0.0.1,::1';
    }
    if (preg_match('/(?:^|,)\s*(?:\*|\*\*|REMOTE_ADDR)\s*(?:,|$)/', $env['TRUSTED_PROXIES'] ?? '')) {
        throw new RuntimeException('TRUSTED_PROXIES must contain explicit proxy addresses.');
    }
    $write = function (string $file, string $contents, int $mode, bool $applicationFile = false) use ($appUser, $appGroup) {
        if (is_link($file) || ! is_dir(dirname($file))) {
            throw new RuntimeException('Unsafe configuration path.');
        }
        if (is_file($file) && file_get_contents($file) === $contents) {
            if (! chmod($file, $mode) || ($applicationFile && PHP_OS_FAMILY !== 'Windows'
                && (! chown($file, $appUser) || ! chgrp($file, $appGroup)))) {
                throw new RuntimeException('Cannot assign production configuration permissions.');
            }

            return;
        }
        $temporary = tempnam(dirname($file), '.teg-config-');
        try {
            if (file_put_contents($temporary, $contents) === false || ! chmod($temporary, $mode)) {
                throw new RuntimeException('Cannot write production configuration.');
            }
            if ($applicationFile && PHP_OS_FAMILY !== 'Windows'
                && (! chown($temporary, $appUser) || ! chgrp($temporary, $appGroup))) {
                throw new RuntimeException('Cannot assign application configuration ownership.');
            }
            if (! rename($temporary, $file)) {
                throw new RuntimeException('Cannot install production configuration.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    };
    foreach ($changes as $key => $value) {
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $source = preg_match($pattern, $source)
            ? preg_replace_callback($pattern, fn () => $key.'='.$value, $source)
            : rtrim($source)."\n".$key.'='.$value."\n";
    }
    $port = $env['DB_PORT'] ?? '5432';
    if (! ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Invalid PostgreSQL port.');
    }
    $escape = fn ($value) => str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $value);
    $passwordFile = $configDirectory.'/teg-approval-backup.pgpass';
    $credentials = implode(':', array_map($escape, [$env['DB_HOST'], $port, $env['DB_DATABASE'], $env['DB_USERNAME'], $env['DB_PASSWORD']]))."\n";
    $backup = [
        'APP_DIR' => $app, 'BACKUP_ROOT' => '/var/backups/teg-approval-bot',
        'APP_USER' => $appUser, 'APP_GROUP' => $appGroup, 'PHP_FPM_SERVICE' => $service,
        'PGHOST' => $env['DB_HOST'], 'PGPORT' => $port, 'PGDATABASE' => $env['DB_DATABASE'],
        'PGUSER' => $env['DB_USERNAME'], 'PGPASSFILE' => $passwordFile,
        'BACKUP_KEEP_DAILY' => '7', 'BACKUP_KEEP_DEPLOY' => '3', 'BACKUP_MIN_FREE_MB' => '256',
    ];
    $backupFile = $configDirectory.'/teg-approval-backup.env';
    // Retain deliberate administrator overrides for storage and retention.
    if (is_file($backupFile) && ! is_link($backupFile)) {
        foreach (file($backupFile, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^(BACKUP_ROOT|BACKUP_KEEP_DAILY|BACKUP_KEEP_DEPLOY|BACKUP_MIN_FREE_MB)=([\'\"]?)([A-Za-z0-9_\/.-]+)\2$/D', $line, $match)) {
                $backup[$match[1]] = $match[3];
            }
        }
    }
    $configuration = '';
    foreach ($backup as $key => $value) {
        $configuration .= $key.'='.escapeshellarg($value)."\n";
    }
    $write($passwordFile, $credentials, 0600);
    $write($backupFile, $configuration, 0600);
    if (file_get_contents($path) !== $source) {
        $write($configDirectory.'/teg-approval-env-before-operations', file_get_contents($path), 0600);
    }
    $write($path, $source, 0640, true);
    echo $botEnabled ? '1' : '0';
};
try {
    $prepare();
} catch (Throwable) {
    fwrite(STDERR, "Production configuration preparation failed. Check required dotenv values and filesystem permissions; no secrets were printed.\n");
    exit(1);
}
