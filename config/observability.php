<?php

return [
    'frontend_dsn' => env('SENTRY_FRONTEND_DSN'),
    'release' => env('APP_RELEASE'),
    'slow_request_ms' => (int) env('LOG_SLOW_REQUEST_MS', 2000),
];
