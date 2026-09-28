<?php

declare(strict_types=1);

return [
    // A job dispatched without a connection runs right away; the label queue names its own (config/labels.php).
    'default' => env('QUEUE_CONNECTION', 'sync'),
    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],
        // Floci plays SQS, through Toxiproxy; the label worker reads the label queue.
        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID', 'test'),
            'secret' => env('AWS_SECRET_ACCESS_KEY', 'test'),
            'prefix' => env('SQS_PREFIX', 'http://toxiproxy:14566/000000000000'),
            'queue' => env('LABELS_QUEUE', 'label-jobs'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'endpoint' => env('AWS_ENDPOINT', 'http://toxiproxy:14566'),
            // Laravel's default waits 60 s for SQS; a queue that slow is a queue that is down.
            'http' => [
                'timeout' => (int) env('SQS_TIMEOUT_MS', 5000) / 1000,
                'connect_timeout' => (int) env('SQS_CONNECT_TIMEOUT_MS', 2000) / 1000,
            ],
            'after_commit' => false,
        ],
    ],
    'failed' => [
        'driver' => 'database-uuids',
        'database' => 'pgsql',
        'table' => 'failed_jobs',
    ],
];
