<?php

declare(strict_types=1);

return [
    // Through Toxiproxy in the stack, so chaos experiments reach the Kafka traffic too.
    'brokers' => env('KAFKA_BROKERS', 'toxiproxy:19092'),

    'client_id' => env('APP_NAME', 'logistics'),
];
