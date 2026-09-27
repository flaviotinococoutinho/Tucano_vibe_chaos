<?php

declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'mysql'),

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
        ],

        // A database of its own, so flushing the cache never touches other keys.
        'cache' => [
            'host' => env('REDIS_HOST', 'toxiproxy'),
            'port' => env('REDIS_PORT', '16379'),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],
];
