<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LegacySignatureCleanup
{
    private const OUTPUT_PREFIX = 'signatures/cleaned-v1/';

    public function __construct(private SignatureCleanerService $cleaner) {}

    public function migrate(bool $apply, array $ids = []): array
    {
        return $this->locked($apply, function () use ($apply, $ids) {
            $result = $this->result();
            $entries = [];
            $signatures = Storage::disk('local');
            $batch = (string) Str::uuid();
            $users = User::query()->whereNotNull('signature_path')
                ->when($ids, fn ($query) => $query->whereIn('id', $ids))->orderBy('id')->get(['id', 'signature_path']);

            // Complete and verify every backup before changing any user record.
            foreach ($users as $user) {
                $path = $user->signature_path;
                if (! $signatures->exists($path)) {
                    $this->failed($result, $user->id, 'Source file is missing');

                    continue;
                }
                if (str_starts_with($path, self::OUTPUT_PREFIX)) {
                    $result['skipped']++;

                    continue;
                }
                $bytes = $signatures->get($path);
                if ($this->alreadyClean($bytes)) {
                    $result['skipped']++;

                    continue;
                }
                $result['candidates']++;
                if (! $apply) {
                    continue;
                }
                $entry = [
                    'user_id' => $user->id,
                    'original_path' => $path,
                    'original_sha256' => hash('sha256', $bytes),
                    'backup_path' => "signature-cleanup/{$batch}/originals/{$user->id}.bin",
                    'cleaned_path' => self::OUTPUT_PREFIX."{$batch}/{$user->id}.png",
                    'cleaned_sha256' => null,
                ];
                $this->writeVerified($entry['backup_path'], $bytes);
                $this->writeEntry($batch, $entry);
                $entries[] = $entry;
            }

            if (! $entries) {
                return $result;
            }
            $result['batch'] = $batch;
            foreach ($entries as $entry) {
                try {
                    // The cleaner reads the verified backup, never a changing upload.
                    $output = $signatures->path($entry['cleaned_path']);
                    if (! $signatures->makeDirectory(dirname($entry['cleaned_path']))) {
                        throw new RuntimeException('Cannot create output directory');
                    }
                    if (! $this->cleaner->clean($signatures->path($entry['backup_path']), $output)) {
                        throw new RuntimeException('Cleaner failed; original retained');
                    }
                    $image = @getimagesize($output);
                    if (! $image || $image[2] !== IMAGETYPE_PNG) {
                        throw new RuntimeException('Cleaner did not produce a valid PNG');
                    }
                    $bytes = $signatures->get($entry['cleaned_path']);
                    $entry['cleaned_sha256'] = hash('sha256', $bytes);
                    // Persist recovery information before switching the database path.
                    $this->writeEntry($batch, $entry);
                    if (! $signatures->exists($entry['original_path']) ||
                        ! hash_equals($entry['original_sha256'], hash('sha256', $signatures->get($entry['original_path'])))) {
                        throw new RuntimeException('Source changed during cleanup; original retained');
                    }
                    $updated = User::query()->whereKey($entry['user_id'])
                        ->where('signature_path', $entry['original_path'])
                        ->update(['signature_path' => $entry['cleaned_path']]);
                    if ($updated !== 1) {
                        throw new RuntimeException('User signature changed during cleanup; newer upload retained');
                    }
                    $result['converted']++;
                } catch (\Throwable $e) {
                    $this->failed($result, $entry['user_id'], $e->getMessage());
                    // Leave files in place: a crash after the DB update remains recoverable.
                }
            }

            return $result;
        });
    }

    public function restore(string $batch, bool $apply): array
    {
        if (! Str::isUuid($batch)) {
            throw new RuntimeException('Invalid backup batch ID');
        }

        return $this->locked($apply, function () use ($batch, $apply) {
            $result = $this->result();
            $result['batch'] = $batch;
            $signatures = Storage::disk('local');
            $files = $signatures->files("signature-cleanup/{$batch}/entries");
            if (! $files) {
                throw new RuntimeException('Backup batch not found');
            }
            foreach ($files as $file) {
                if (! str_ends_with($file, '.json')) {
                    continue;
                }
                $entry = json_decode($signatures->get($file), true, flags: JSON_THROW_ON_ERROR);
                $user = User::find($entry['user_id']);
                if (! $user || $user->signature_path !== $entry['cleaned_path']) {
                    $result['skipped']++;

                    continue;
                }
                try {
                    $backup = $signatures->get($entry['backup_path']);
                    if (! is_string($backup) || ! hash_equals($entry['original_sha256'], hash('sha256', $backup))) {
                        throw new RuntimeException('Backup is missing or corrupted');
                    }
                    if (! $signatures->exists($entry['cleaned_path']) || ! $entry['cleaned_sha256'] ||
                        ! hash_equals($entry['cleaned_sha256'], hash('sha256', $signatures->get($entry['cleaned_path'])))) {
                        throw new RuntimeException('Cleaned file changed; refusing to overwrite a newer signature');
                    }
                    if ($signatures->exists($entry['original_path']) &&
                        ! hash_equals($entry['original_sha256'], hash('sha256', $signatures->get($entry['original_path'])))) {
                        throw new RuntimeException('Original path now contains a different file');
                    }
                    $result['candidates']++;
                    if (! $apply) {
                        continue;
                    }
                    if (! $signatures->exists($entry['original_path'])) {
                        if (! $signatures->put($entry['original_path'], $backup) ||
                            ! hash_equals($entry['original_sha256'], hash('sha256', $signatures->get($entry['original_path'])))) {
                            throw new RuntimeException('Could not restore original file');
                        }
                    }
                    $updated = User::query()->whereKey($entry['user_id'])
                        ->where('signature_path', $entry['cleaned_path'])
                        ->update(['signature_path' => $entry['original_path']]);
                    if ($updated !== 1) {
                        throw new RuntimeException('User signature changed during rollback; newer upload retained');
                    }
                    $result['restored']++;
                } catch (\Throwable $e) {
                    $this->failed($result, $entry['user_id'], $e->getMessage());
                }
            }

            return $result;
        });
    }

    private function alreadyClean(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[2] !== IMAGETYPE_PNG || max($info[0], $info[1]) > 1600) {
            return false;
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return false;
        }
        try {
            $ink = false;
            $paper = false;
            for ($y = 0; $y < imagesy($image); $y++) {
                for ($x = 0; $x < imagesx($image); $x++) {
                    $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    // Preserve transparent PNGs as well as existing binary cleaner output.
                    if ($color['alpha'] === 127) {
                        $paper = true;

                        continue;
                    }
                    if ($color['red'] !== $color['green'] || $color['red'] !== $color['blue'] ||
                        ! in_array($color['red'], [0, 255], true)) {
                        return false;
                    }
                    $ink = $ink || $color['red'] === 0;
                    $paper = $paper || $color['red'] === 255;
                }
            }

            return $ink && $paper;
        } finally {
            imagedestroy($image);
        }
    }

    private function writeEntry(string $batch, array $entry): void
    {
        $this->writeVerified("signature-cleanup/{$batch}/entries/{$entry['user_id']}.json",
            json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function writeVerified(string $path, string $bytes): void
    {
        $disk = Storage::disk('local');
        $temporary = $path.'.'.Str::uuid().'.tmp';
        if (! $disk->put($temporary, $bytes) || ! hash_equals(hash('sha256', $bytes), hash('sha256', $disk->get($temporary))) ||
            ! $disk->move($temporary, $path) || ! hash_equals(hash('sha256', $bytes), hash('sha256', $disk->get($path)))) {
            throw new RuntimeException("Backup verification failed: {$path}. No further signatures will be changed.");
        }
    }

    private function locked(bool $apply, callable $operation): array
    {
        if (! $apply) {
            return $operation();
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory('signature-cleanup');
        $lock = fopen($disk->path('signature-cleanup/.lock'), 'c');
        if (! $lock) {
            throw new RuntimeException('Cannot open signature cleanup lock');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another signature cleanup or rollback is running');
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function result(): array
    {
        return ['batch' => null, 'candidates' => 0, 'converted' => 0, 'restored' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    }

    private function failed(array &$result, int $userId, string $message): void
    {
        $result['failed']++;
        $result['errors'][] = "User {$userId}: {$message}";
    }
}
