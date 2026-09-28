<?php

declare(strict_types=1);

return [
    // Floci plays S3 and SQS locally (infra/floci/ready.d creates the bucket and the queue),
    // reached through Toxiproxy like every other dependency. The credentials are Floci's: any value goes.
    'bucket' => env('LABELS_BUCKET', 'tucano-labels'),

    'queue' => [
        'connection' => env('LABELS_QUEUE_CONNECTION', 'sqs'),
        'name' => env('LABELS_QUEUE', 'label-jobs'),
    ],

    's3' => [
        'endpoint' => env('AWS_ENDPOINT', 'http://toxiproxy:14566'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'key' => env('AWS_ACCESS_KEY_ID', 'test'),
        'secret' => env('AWS_SECRET_ACCESS_KEY', 'test'),
    ],
];
