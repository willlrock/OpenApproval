<?php

return [
    'pdftotext_binary' => env('PDFTOTEXT_BINARY', 'pdftotext'),
    'timeout' => (int) env('PDF_IMPORT_TIMEOUT', 30),
    'max_file_size' => (int) env('PDF_IMPORT_MAX_FILE_SIZE', 10 * 1024 * 1024),
];
