<?php

return [
    // Debian/Ubuntu ship only `python3`; a bare `python` does not exist there.
    'python_bin' => env('SIGNATURE_PYTHON_BIN', 'python3'),
    'timeout'    => (int) env('SIGNATURE_CLEAN_TIMEOUT', 30),
];
