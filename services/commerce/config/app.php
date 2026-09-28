<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'commerce'),
    // local, staging or production; production turns the chaos and lab flags off (ProductionGuard).
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost:8082'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
];
