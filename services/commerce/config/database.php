<?php

declare(strict_types=1);

$redis = static fn(string $database): array => [
    'host' => env('REDIS_HOST', 'toxiproxy'),
    'port' => (int) env('REDIS_PORT', 16379),
    'password' => env('REDIS_PASSWORD'),
    'database' => $database,
    // phpredis waits forever by default: a Redis that stops answering would hold the request.
    'timeout' => (int) env('REDIS_TIMEOUT_MS', 1000) / 1000,
    'read_timeout' => (int) env('REDIS_READ_TIMEOUT_MS', 1000) / 1000,
    'max_retries' => (int) env('REDIS_MAX_RETRIES', 3),
    'backoff_algorithm' => 'decorrelated_jitter',
    'backoff_base' => (int) env('REDIS_BACKOFF_BASE_MS', 100),
    'backoff_cap' => (int) env('REDIS_BACKOFF_CAP_MS', 1000),
];

return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            // Through Toxiproxy in the stack (the commerce-postgres proxy), so chaos experiments reach the database.
            'host' => env('DB_HOST', 'toxiproxy'),
            'port' => (int) env('DB_PORT', 15432),
            'database' => env('DB_DATABASE', 'commerce'),
            'username' => env('DB_USERNAME', 'commerce'),
            'password' => env('DB_PASSWORD', 'commerce'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            // pdo_pgsql hands this to libpq as connect_timeout, which covers the whole
            // handshake. The default is 30 s, as long as nginx waits for the request.
            'options' => [PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT_SECONDS', 2)],
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
    'redis' => [
        'client' => 'phpredis',
        'options' => [
            'prefix' => env('REDIS_PREFIX', 'commerce-database-'),
        ],
        'default' => $redis((string) env('REDIS_DB', '0')),
        'cache' => $redis((string) env('REDIS_CACHE_DB', '1')),
    ],
];
