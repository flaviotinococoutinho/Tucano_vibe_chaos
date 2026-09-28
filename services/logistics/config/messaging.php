<?php

declare(strict_types=1);

return [
    // Through Toxiproxy in the stack, so chaos experiments reach the Kafka traffic too.
    'brokers' => env('KAFKA_BROKERS', 'toxiproxy:19092'),
    'client_id' => env('APP_NAME', 'logistics'),
    'producer' => [
        // How long the producer waits to fill a batch before it sends one.
        'linger_ms' => (int) env('KAFKA_PRODUCER_LINGER_MS', 5),
        // A message not acknowledged by then fails, and the outbox relay rolls back and tries again.
        'message_timeout_ms' => (int) env('KAFKA_PRODUCER_MESSAGE_TIMEOUT_MS', 10000),
    ],
    'consumer' => [
        // How long one poll waits for a record; also how long a SIGTERM may take to be noticed.
        'poll_timeout_ms' => (int) env('KAFKA_CONSUMER_POLL_TIMEOUT_MS', 1000),
        // A handler that fails on something that may pass is tried again, with exponential
        // backoff and full jitter, and then goes to the dead letter topic (RetryPolicy).
        'retry' => [
            'max_attempts' => (int) env('KAFKA_CONSUMER_RETRY_MAX_ATTEMPTS', 5),
            'base_delay_ms' => (int) env('KAFKA_CONSUMER_RETRY_BASE_DELAY_MS', 200),
            'max_delay_ms' => (int) env('KAFKA_CONSUMER_RETRY_MAX_DELAY_MS', 5000),
        ],
    ],
    // A paid order can arrive before the catalog copy has its products, above all when the order
    // intake and the catalog sync start together: half a minute of retries outlasts the catalog
    // consumer joining its group, so those orders do not end up in the dead letter topic.
    'order_intake' => [
        'retry' => [
            'max_attempts' => (int) env('ORDER_INTAKE_RETRY_MAX_ATTEMPTS', 8),
            'base_delay_ms' => (int) env('ORDER_INTAKE_RETRY_BASE_DELAY_MS', 500),
            'max_delay_ms' => (int) env('ORDER_INTAKE_RETRY_MAX_DELAY_MS', 10000),
        ],
    ],
    'outbox' => [
        // At most this many events published per transaction of the relay.
        'batch_size' => (int) env('OUTBOX_RELAY_BATCH_SIZE', 100),
        // The pause of the relay after a round with nothing to publish, and after a failed one.
        'idle_pause_ms' => (int) env('OUTBOX_RELAY_IDLE_PAUSE_MS', 200),
        'failure_pause_ms' => (int) env('OUTBOX_RELAY_FAILURE_PAUSE_MS', 2000),
    ],
];
