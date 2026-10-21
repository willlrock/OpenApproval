<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;

class TelegramFileCache
{
    public function resolve(string $fileId): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{10,512}$/D', $fileId), 404);
        $path = 'telegram-cache/'.hash('sha256', $fileId);
        if (Storage::disk('local')->exists($path)) {
            return $path;
        }

        return Cache::lock('telegram-file:'.hash('sha256', $fileId), 90)->block(5, function () use ($fileId, $path) {
            if (Storage::disk('local')->exists($path)) {
                return $path;
            }
            try {
                $file = app(Nutgram::class)->getFile($fileId);
            } catch (\Throwable) {
                abort(502, 'Не удалось получить файл из Telegram. Повторите позже.');
            }
            abort_unless($file?->file_path && preg_match('#^[a-zA-Z0-9_./-]+$#D', $file->file_path) && ! str_contains($file->file_path, '..'), 404);
            abort_if(($file->file_size ?? 0) > 20 * 1024 * 1024, 413);
            Storage::disk('local')->makeDirectory('telegram-cache');
            $temporary = Storage::disk('local')->path($path.'.'.\Illuminate\Support\Str::uuid().'.tmp');
            try {
                Http::connectTimeout(5)->timeout(45)->withOptions([
                    'sink' => $temporary,
                    'progress' => function ($total, $downloaded) {
                        if ($total > 20 * 1024 * 1024 || $downloaded > 20 * 1024 * 1024) {
                            throw new \RuntimeException('File too large.');
                        }
                    },
                ])->get('https://api.telegram.org/file/bot'.config('nutgram.token').'/'.$file->file_path)->throw();
                abort_if(filesize($temporary) > 20 * 1024 * 1024, 413);
                if (! rename($temporary, Storage::disk('local')->path($path))) {
                    throw new \RuntimeException('Unable to cache file.');
                }
            } catch (\Throwable) {
                // Transport exceptions contain the bot token in the URL. Never log them.
                abort(502, 'Не удалось загрузить файл из Telegram. Повторите позже.');
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            return $path;
        });
    }

    public function download(?string $path, ?string $fileId, string $name = 'document')
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            abort_unless($fileId, 404);
            $path = $this->resolve($fileId);
        }

        return Storage::disk('local')->download($path, basename($name));
    }

    public function temporaryCopy(string $fileId): string
    {
        $cached = $this->resolve($fileId);
        $temporary = tempnam(sys_get_temp_dir(), 'teg-import-');
        if ($temporary === false) {
            throw new \RuntimeException('Не удалось подготовить временный файл.');
        }
        try {
            if (! copy(Storage::disk('local')->path($cached), $temporary)) {
                throw new \RuntimeException('Не удалось подготовить временный файл.');
            }
        } catch (\Throwable $e) {
            unlink($temporary);
            throw $e;
        }

        return $temporary;
    }
}
