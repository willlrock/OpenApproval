<?php

use App\Models\User;
use App\Services\LegacySignatureCleanup;
use App\Services\ManagementDocumentService;
use App\Services\SignatureCleanerService;
use Illuminate\Support\Facades\Storage;

function legacySignatureImage(bool $clean = false): string
{
    $image = imagecreatetruecolor(80, 40);
    imagefill($image, 0, 0, imagecolorallocate($image, $clean ? 255 : 230, $clean ? 255 : 225, $clean ? 255 : 220));
    imageline($image, 10, 30, 70, 10, imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function legacySigner(string $path = 'signatures/legacy.png'): User
{
    $user = User::factory()->create(['signature_path' => $path]);
    Storage::disk('local')->put($path, legacySignatureImage());

    return $user;
}

function fakeSignatureCleaner(?Closure $callback = null): void
{
    $cleaner = Mockery::mock(SignatureCleanerService::class);
    $cleaner->shouldReceive('clean')->andReturnUsing($callback ?? function ($input, $output) {
        file_put_contents($output, legacySignatureImage(true));

        return true;
    });
    app()->instance(SignatureCleanerService::class, $cleaner);
}

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

test('preview makes no database or backup changes and skips existing binary PNGs', function () {
    $user = legacySigner();
    $clean = legacySigner('signatures/already-clean.png');
    Storage::disk('local')->put($clean->signature_path, legacySignatureImage(true));
    $this->mock(SignatureCleanerService::class)->shouldNotReceive('clean');

    $this->artisan('signatures:clean-legacy')->assertSuccessful();

    expect($user->fresh()->signature_path)->toBe('signatures/legacy.png')
        ->and(Storage::disk('local')->allFiles('signature-cleanup'))->toBe([]);
});

test('all originals and recovery entries are backed up before the first conversion', function () {
    $first = legacySigner();
    $second = legacySigner('signatures/second.jpg');
    fakeSignatureCleaner(function ($input, $output) {
        $files = Storage::disk('local')->allFiles('signature-cleanup');
        expect(count(array_filter($files, fn ($file) => str_ends_with($file, '.bin'))))->toBe(2)
            ->and(count(array_filter($files, fn ($file) => str_ends_with($file, '.json'))))->toBe(2)
            ->and(file_get_contents($input))->toBe(legacySignatureImage());
        file_put_contents($output, legacySignatureImage(true));

        return true;
    });

    $result = app(LegacySignatureCleanup::class)->migrate(true);

    expect($result['converted'])->toBe(2)->and($result['failed'])->toBe(0);
    foreach ([$first, $second] as $user) {
        expect($user->fresh()->signature_path)->toStartWith('signatures/cleaned-v1/')
            ->and(Storage::disk('local')->get($user->signature_path))->toBe(legacySignatureImage())
            ->and(Storage::disk('local')->get("signature-cleanup/{$result['batch']}/originals/{$user->id}.bin"))->toBe(legacySignatureImage());
    }
});

test('backup failure aborts the entire batch before any signature changes', function () {
    $user = legacySigner();
    $this->mock(SignatureCleanerService::class)->shouldNotReceive('clean');
    $private = Storage::disk('local');
    $private->makeDirectory('signature-cleanup');
    $disk = Mockery::mock($private)->makePartial();
    $disk->shouldReceive('put')->andReturn(false);
    Storage::set('local', $disk);

    $this->artisan('signatures:clean-legacy', ['--apply' => true])->assertFailed();
    expect($user->fresh()->signature_path)->toBe($user->signature_path);
});

test('cleaner failure and invalid output retain original signatures', function ($invalid) {
    $user = legacySigner();
    fakeSignatureCleaner(function ($input, $output) use ($invalid) {
        if ($invalid) {
            file_put_contents($output, 'not a PNG');
        }

        return $invalid;
    });

    $result = app(LegacySignatureCleanup::class)->migrate(true);
    expect($result['failed'])->toBe(1)->and($result['converted'])->toBe(0)
        ->and($user->fresh()->signature_path)->toBe($user->signature_path);
})->with([false, true]);

test('concurrent user upload wins over the migration', function () {
    $user = legacySigner();
    fakeSignatureCleaner(function ($input, $output) use ($user) {
        $user->update(['signature_path' => 'signatures/new-upload.png']);
        file_put_contents($output, legacySignatureImage(true));

        return true;
    });
    $result = app(LegacySignatureCleanup::class)->migrate(true);

    expect($result['failed'])->toBe(1)->and($user->fresh()->signature_path)->toBe('signatures/new-upload.png');
});

test('repeated migration skips converted signatures without new backups', function () {
    legacySigner();
    fakeSignatureCleaner();
    app(LegacySignatureCleanup::class)->migrate(true);
    $files = Storage::disk('local')->allFiles();
    $this->mock(SignatureCleanerService::class)->shouldNotReceive('clean');

    $result = app(LegacySignatureCleanup::class)->migrate(true);
    expect($result['skipped'])->toBe(1)->and($result['batch'])->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe($files);
});

test('rollback previews and restores verified originals even if the original file was removed', function () {
    $user = legacySigner();
    fakeSignatureCleaner();
    $result = app(LegacySignatureCleanup::class)->migrate(true);
    $cleaned = $user->fresh()->signature_path;
    Storage::disk('local')->delete($user->signature_path);

    $this->artisan('signatures:clean-legacy', ['--restore' => $result['batch']])->assertSuccessful();
    expect($user->fresh()->signature_path)->toBe($cleaned);
    $this->artisan('signatures:clean-legacy', ['--restore' => $result['batch'], '--apply' => true])->assertSuccessful();
    expect($user->fresh()->signature_path)->toBe($user->signature_path)
        ->and(Storage::disk('local')->get($user->signature_path))->toBe(legacySignatureImage());
});

test('rollback preserves newer uploads and refuses corrupted backups', function () {
    $first = legacySigner();
    $second = legacySigner('signatures/second.jpg');
    fakeSignatureCleaner();
    $result = app(LegacySignatureCleanup::class)->migrate(true);
    $first->update(['signature_path' => 'signatures/newer.png']);
    Storage::disk('local')->put("signature-cleanup/{$result['batch']}/originals/{$second->id}.bin", 'corrupted');
    $cleaned = $second->fresh()->signature_path;

    $rollback = app(LegacySignatureCleanup::class)->restore($result['batch'], true);
    expect($rollback['skipped'])->toBe(1)->and($rollback['failed'])->toBe(1)
        ->and($first->fresh()->signature_path)->toBe('signatures/newer.png')
        ->and($second->fresh()->signature_path)->toBe($cleaned);
});

test('cleanup preserves existing document snapshots and their hashes', function () {
    $user = legacySigner();
    $request = \App\Models\ApprovalRequest::create([
        'project_id' => makeProject()->id, 'applicant_id' => $user->id,
        'type' => 'expense', 'name' => 'Old request', 'status' => 'pending_approval',
    ]);
    app(ManagementDocumentService::class)->captureApplicantSignature($request);
    $snapshot = $request->fresh()->applicant_signature_snapshot_path;
    $hash = $request->fresh()->applicant_signature_sha256;
    fakeSignatureCleaner();

    app(LegacySignatureCleanup::class)->migrate(true);

    expect($request->fresh()->applicant_signature_snapshot_path)->toBe($snapshot)
        ->and($request->fresh()->applicant_signature_sha256)->toBe($hash)
        ->and(hash('sha256', Storage::disk('local')->get($snapshot)))->toBe($hash);
});

test('user filter limits the batch and invalid rollback IDs are rejected', function () {
    $first = legacySigner();
    $second = legacySigner('signatures/second.jpg');
    fakeSignatureCleaner();
    $this->artisan('signatures:clean-legacy', ['--apply' => true, '--user' => [$first->id]])->assertSuccessful();
    expect($second->fresh()->signature_path)->toBe($second->signature_path);
    $this->artisan('signatures:clean-legacy', ['--restore' => '../outside', '--apply' => true])->assertFailed();
});
