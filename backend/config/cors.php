<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(explode(',', env('BABI_WEB_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'Authorization', 'X-Operation-Key'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
