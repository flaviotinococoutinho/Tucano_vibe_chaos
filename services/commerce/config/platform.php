<?php

declare(strict_types=1);

return [
    'service' => env('APP_NAME', 'commerce'),

    'environment' => env('APP_ENV', 'production'),

    'flags' => [
        // flagd in the stack, memory in tests.
        'driver' => env('FLAGS_DRIVER', 'flagd'),
        'host' => env('FLAGD_HOST', 'flagd'),
        'port' => (int) env('FLAGD_PORT', 8013),
        'cache_seconds' => (int) env('FLAGS_CACHE_SECONDS', 2),
    ],

    'snowflake' => [
        'datacenter' => (int) env('SNOWFLAKE_DATACENTER_ID', 1),
        'worker' => (int) env('SNOWFLAKE_WORKER_ID', 1),
    ],
];
