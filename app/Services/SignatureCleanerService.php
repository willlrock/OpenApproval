<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class SignatureCleanerService
{
    public function clean(string $inputAbsolutePath, string $outputAbsolutePath): bool
    {
        $script = base_path('scripts/clean_signature.py');

        if (!is_file($script)) {
            Log::error("Signature cleaner script missing", ['path' => $script]);
            return false;
        }

        if (!is_file($inputAbsolutePath)) {
            Log::error("Signature cleaner input missing", ['path' => $inputAbsolutePath]);
            return false;
        }

        $python = config('signature.python_bin', 'python');
        $timeout = (int) config('signature.timeout', 30);

        $process = new Process([$python, $script, $inputAbsolutePath, $outputAbsolutePath]);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            Log::error('Signature cleaner timed out', ['timeout' => $timeout]);
            return false;
        } catch (\Throwable $e) {
            Log::error('Signature cleaner process failed to start', ['error' => $e->getMessage()]);
            return false;
        }

        if (!$process->isSuccessful()) {
            Log::error('Signature cleaner exited non-zero', [
                'exit_code' => $process->getExitCode(),
                'stdout'    => $process->getOutput(),
                'stderr'    => $process->getErrorOutput(),
            ]);
            return false;
        }

        return is_file($outputAbsolutePath) && filesize($outputAbsolutePath) > 0;
    }
}
