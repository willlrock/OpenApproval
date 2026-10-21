<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PdfSignatureRenderer
{
    public function dataUri(?string $path, ?string $expectedHash, ?string $profilePath = null, string $label = 'Подпись'): ?string
    {
        $disk = Storage::disk('local');
        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);
        if (! hash_equals($expectedHash ?? '', hash('sha256', $bytes))) {
            throw new RuntimeException("{$label} повреждена.");
        }

        // Reuse the verified cleanup of this exact snapshot; a later replacement
        // signature in the profile must not change who signed the document.
        if ($profilePath && preg_match('#^signatures/cleaned-v1/([a-f0-9-]{36})/(\d+)\.png$#', $profilePath, $match)) {
            $entryPath = "signature-cleanup/{$match[1]}/entries/{$match[2]}.json";
            if ($disk->exists($entryPath) && $disk->exists($profilePath)) {
                $entry = json_decode($disk->get($entryPath), true);
                if (is_array($entry) && ($entry['cleaned_path'] ?? null) === $profilePath &&
                    is_string($entry['original_sha256'] ?? null) && is_string($entry['cleaned_sha256'] ?? null) &&
                    hash_equals($expectedHash, $entry['original_sha256'] ?? '')) {
                    $cleaned = $disk->get($profilePath);
                    $image = @getimagesizefromstring($cleaned);
                    if ($image && $image[2] === IMAGETYPE_PNG &&
                        hash_equals($entry['cleaned_sha256'] ?? '', hash('sha256', $cleaned))) {
                        $bytes = $cleaned;
                    }
                }
            }
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
