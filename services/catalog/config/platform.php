<?php

declare(strict_types=1);

return [
    'service' => env('APP_NAME', 'catalog'),

    // local, staging or production; production turns the chaos and lab flags off (ProductionGuard).
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

    'kafka' => [
        'brokers' => env('KAFKA_BROKERS', 'toxiproxy:19092'),
        // How long the producer waits to fill a batch before it sends one.
        'linger_ms' => (int) env('KAFKA_PRODUCER_LINGER_MS', 5),
        // A write waits this long for Kafka at most, after MySQL has already committed it.
        'message_timeout_ms' => (int) env('KAFKA_PRODUCER_MESSAGE_TIMEOUT_MS', 5000),
    ],
];
