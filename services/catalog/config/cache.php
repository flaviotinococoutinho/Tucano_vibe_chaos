<?php

declare(strict_types=1);

return [
    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'catalog-cache-'),
];
