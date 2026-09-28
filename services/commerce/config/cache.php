<?php

declare(strict_types=1);

return [
    // redis in the stack, array in the tests.
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
    'prefix' => env('CACHE_PREFIX', 'commerce-cache-'),
    // Nothing in the cache is a PHP object, so nothing coming back from it is unserialized into one.
    'serializable_classes' => false,
];
