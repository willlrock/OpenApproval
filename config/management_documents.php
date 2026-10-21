<?php

return [
    'template_paths' => [
        'order' => resource_path('documents/management_order_template.docx'),
        'resolution' => resource_path('documents/management_resolution_template.docx'),
    ],
    'soffice_binary' => env('SOFFICE_BINARY', 'soffice'),
    'pdfunite_binary' => env('PDFUNITE_BINARY', 'pdfunite'),
    'conversion_timeout' => (int) env('DOCUMENT_CONVERSION_TIMEOUT', 120),
    'max_upload_kb' => (int) env('MANAGEMENT_DOCUMENT_MAX_UPLOAD_KB', 20480),
];
