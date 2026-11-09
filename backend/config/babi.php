<?php

return [
    'policy_version' => env('BABI_POLICY_VERSION', '2026-10-17'),
    // Select only a catalog independently reviewed before deployment.
    'vaccine_catalog_version' => env('BABI_VACCINE_CATALOG_VERSION'),
    'calendar_timezone' => 'Asia/Ho_Chi_Minh',
    'frontend_url' => env('BABI_FRONTEND_URL', 'http://127.0.0.1:5173'),
];
