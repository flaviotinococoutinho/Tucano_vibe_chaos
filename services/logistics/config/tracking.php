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
        // The projector writes in the background, with the SDK's retries behind each call.
        'timeout_ms' => (int) env('DYNAMODB_TIMEOUT_MS', 3000),
        'connect_timeout_ms' => (int) env('DYNAMODB_CONNECT_TIMEOUT_MS', 1000),
        // The page answers a person waiting: one try, and a slow DynamoDB is a 503, not a hanging request.
        'read_timeout_ms' => (int) env('DYNAMODB_READ_TIMEOUT_MS', 800),
    ],
    // A page is kept this many days after its last step; DynamoDB's TTL removes it afterwards.
    'page_retention_days' => (int) env('TRACKING_PAGE_RETENTION_DAYS', 90),
];
