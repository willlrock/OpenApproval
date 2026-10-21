<?php

namespace App\Services\PdfImport;

use RuntimeException;
use Symfony\Component\Process\Process;

class PdfTextExtractor
{
    public function extract(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('PDF file is not readable.');
        }

        $maxFileSize = (int) config('pdf_import.max_file_size');
        if ($maxFileSize > 0 && filesize($path) > $maxFileSize) {
            throw new RuntimeException('PDF file is too large for import.');
        }

        $process = new Process([
            (string) config('pdf_import.pdftotext_binary', 'pdftotext'),
            '-layout',
            '-enc',
            'UTF-8',
            $path,
            '-',
        ]);
        $process->setTimeout((int) config('pdf_import.timeout', 30));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Failed to extract PDF text.');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $process->getOutput());
        if (trim($text) === '') {
            throw new RuntimeException('PDF text layer is empty.');
        }

        return $text;
    }
}
