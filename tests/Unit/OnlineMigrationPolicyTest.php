<?php

use App\Support\OnlineMigrationPolicy;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/teg-migration-policy-'.bin2hex(random_bytes(8));
    mkdir($this->directory);
    $this->migration = $this->directory.'/migration.php';
    $this->policy = $this->directory.'/policy.json';
    file_put_contents($this->migration, '<?php // reviewed migration');
});

afterEach(function () {
    foreach (glob($this->directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($this->directory);
});

it('accepts an update without pending database changes', function () {
    file_put_contents($this->policy, '{}');
    OnlineMigrationPolicy::check([], $this->policy);
    expect(true)->toBeTrue();
});

it('accepts only the exact reviewed migration contents', function () {
    file_put_contents($this->policy, json_encode(['reviewed' => hash_file('sha256', $this->migration)]));
    OnlineMigrationPolicy::check(['reviewed' => $this->migration], $this->policy);
    file_put_contents($this->migration, '<?php // changed after review');
    expect(fn () => OnlineMigrationPolicy::check(['reviewed' => $this->migration], $this->policy))
        ->toThrow(RuntimeException::class, 'requires compatibility review');
});

it('rejects an unreviewed migration', function () {
    file_put_contents($this->policy, '{}');
    expect(fn () => OnlineMigrationPolicy::check(['unknown' => $this->migration], $this->policy))
        ->toThrow(RuntimeException::class, 'DEPLOY_MAINTENANCE=1');
});

it('rejects malformed or non-string approvals', function ($contents) {
    file_put_contents($this->policy, $contents);
    expect(fn () => OnlineMigrationPolicy::check(['reviewed' => $this->migration], $this->policy))
        ->toThrow(Exception::class);
})->with(['{', 'null', '{"reviewed":true}', '{"reviewed":123}', '{"reviewed":"wrong"}']);
