<?php

declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'toxiproxy'),
            'port' => env('DB_PORT', '13306'),
            'database' => env('DB_DATABASE', 'catalog'),
            'username' => env('DB_USERNAME', 'catalog'),
            'password' => env('DB_PASSWORD', 'catalog'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            // Every session runs in UTC, so DATETIME(6) columns always hold UTC.
            'timezone' => '+00:00',
            'prefix' => '',
            'strict' => true,
            // mysqlnd only uses this to open the socket. Reads, the server greeting included,
            // follow mysqlnd.net_read_timeout (a day by default), set by PlatformServiceProvider.
            // Laravel retries a read timeout as a lost connection, so keep it short.
            'options' => [PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 2)],
            'read_timeout' => (int) env('DB_READ_TIMEOUT', 2),
        ],
    ],

    'redis' => [
        'client' => 'phpredis',

        'options' => [
            // Every service shares the same Redis, so keys start with the service name.
            'prefix' => env('REDIS_PREFIX', 'catalog-database-'),
        ],

        'default' => [
            'host' => env('REDIS_HOST', 'toxiproxy'),
            'port' => env('REDIS_PORT', '16379'),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_DB', '0'),
            // phpredis waits forever by default: a Redis that stops answering would hold the request.
            'timeout' => (float) env('REDIS_TIMEOUT', 1),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 1),
        ],

        // A database of its own, so flushing the cache never touches other keys.
        'cache' => [
            'host' => env('REDIS_HOST', 'toxiproxy'),
            'port' => env('REDIS_PORT', '16379'),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'timeout' => (float) env('REDIS_TIMEOUT', 1),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 1),
        ],
    ],
];
