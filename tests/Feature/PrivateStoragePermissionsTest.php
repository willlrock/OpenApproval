<?php

use Illuminate\Support\Facades\Storage;

test('private storage creates group writable files and directories without access for others', function () {
    $root = sys_get_temp_dir().'/teg-private-'.bin2hex(random_bytes(8));
    // Deployment establishes the shared group on the root; mkdir inherits setgid.
    mkdir($root, 0770);
    chmod($root, 02770);
    $disk = Storage::build(array_replace(config('filesystems.disks.local'), ['root' => $root]));
    try {
        expect($disk->put('attachments/drafts/image.png', 'image'))->toBeTrue();
        clearstatcache();
        expect(fileperms($root.'/attachments/drafts') & 02777)->toBe(02770)
            ->and(fileperms($root.'/attachments/drafts/image.png') & 0777)->toBe(0660);
    } finally {
        $disk->deleteDirectory('');
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'Unix file permissions');
