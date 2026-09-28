<?php

declare(strict_types=1);

return [
    // Floci plays S3 and SQS locally (infra/floci/ready.d creates the bucket and the queue),
    // reached through Toxiproxy like every other dependency. The credentials are Floci's: any value goes.
    'bucket' => env('LABELS_BUCKET', 'tucano-labels'),
    'queue' => [
        // The connection of config/queue.php, and the queue the label worker reads.
        'connection' => 'sqs',
        'name' => env('LABELS_QUEUE', 'label-jobs'),
    ],
    // The tries of a label job agree with the queue infra/floci/ready.d makes: as many tries as the
    // maxReceiveCount of its redrive policy (3), and a timeout below its visibility timeout (60 s).
    'job' => [
        'tries' => (int) env('LABELS_JOB_TRIES', 3),
        // Seconds before each try after the first, comma separated.
        'backoff_seconds' => array_map(intval(...), explode(',', (string) env('LABELS_JOB_BACKOFF_SECONDS', '5,20'))),
        'timeout_seconds' => (int) env('LABELS_JOB_TIMEOUT_SECONDS', 30),
    ],
    's3' => [
        'endpoint' => env('AWS_ENDPOINT', 'http://toxiproxy:14566'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'key' => env('AWS_ACCESS_KEY_ID', 'test'),
        'secret' => env('AWS_SECRET_ACCESS_KEY', 'test'),
        'timeout_ms' => (int) env('S3_TIMEOUT_MS', 5000),
        'connect_timeout_ms' => (int) env('S3_CONNECT_TIMEOUT_MS', 2000),
    ],
];
