<?php

declare(strict_types=1);

return [
    'service' => env('APP_NAME', 'catalog'),

    'environment' => env('APP_ENV', 'production'),

    'flags' => [
        // flagd in the stack, memory in tests.
        'driver' => env('FLAGS_DRIVER', 'flagd'),
        'host' => env('FLAGD_HOST', 'toxiproxy'),
        'port' => (int) env('FLAGD_PORT', 18013),
        'cache_seconds' => (int) env('FLAGS_CACHE_SECONDS', 2),
    ],
];
