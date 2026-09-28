<?php

declare(strict_types=1);

return [
    'service' => env('APP_NAME', 'commerce'),
    'environment' => env('APP_ENV', 'production'),
    'flags' => [
        // flagd in the stack, through Toxiproxy; memory in the tests.
        'driver' => env('FLAGS_DRIVER', 'flagd'),
        'host' => env('FLAGD_HOST', 'toxiproxy'),
        'port' => (int) env('FLAGD_PORT', 18013),
        // An evaluation is kept this long, so a flag read all the time is one call every few seconds.
        'cache_seconds' => (int) env('FLAGS_CACHE_SECONDS', 2),
        // A flag never slows a request down: past these, the fallback of the call wins.
        'timeout_ms' => (int) env('FLAGD_TIMEOUT_MS', 300),
        'connect_timeout_ms' => (int) env('FLAGD_CONNECT_TIMEOUT_MS', 200),
    ],
    // The node of the Snowflake ids (order numbers): each process that makes ids needs its own worker id.
    'snowflake' => [
        'datacenter' => (int) env('SNOWFLAKE_DATACENTER_ID', 1),
        'worker' => (int) env('SNOWFLAKE_WORKER_ID', 1),
    ],
    // An Idempotency-Key is remembered this long; the same key after that is a new request.
    'idempotency' => [
        'ttl_hours' => (int) env('IDEMPOTENCY_KEYS_TTL_HOURS', 24),
    ],
    // PostgreSQL may refuse a serializable transaction (SQLSTATE 40001) instead of letting it
    // see an anomaly; it runs again from the start, up to this many times in all.
    'transactions' => [
        'serializable_attempts' => (int) env('DB_SERIALIZABLE_ATTEMPTS', 5),
    ],
];
