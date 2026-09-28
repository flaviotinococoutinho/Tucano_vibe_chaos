<?php

declare(strict_types=1);

return [
    // The public tracking pages (UC-SHP-10) live in DynamoDB, played by Floci and reached through Toxiproxy.
    'dynamodb' => [
        'endpoint' => env('AWS_ENDPOINT', 'http://toxiproxy:14566'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'key' => env('AWS_ACCESS_KEY_ID', 'test'),
        'secret' => env('AWS_SECRET_ACCESS_KEY', 'test'),
        'table' => env('TRACKING_TABLE', 'tracking_lookup'),
    ],
    // A page is kept this many days after its last step; DynamoDB's TTL removes it afterwards.
    'keep_days' => (int) env('TRACKING_KEEP_DAYS', 90),
];
