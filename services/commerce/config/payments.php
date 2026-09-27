<?php

declare(strict_types=1);

return [
    'payfake' => [
        // Through Toxiproxy (the payfake proxy), so chaos experiments can slow the provider down.
        'url' => env('PAYFAKE_URL', 'http://toxiproxy:14001'),
        'timeout_ms' => (int) env('PAYFAKE_TIMEOUT_MS', 2000),
        // Shared with PayFake to sign its webhooks.
        'webhook_secret' => env('PAYFAKE_WEBHOOK_SECRET', 'whsec_local_payfake'),
    ],

    'circuit' => [
        'failure_threshold' => (int) env('PAYFAKE_CIRCUIT_FAILURES', 5),
        'window_seconds' => (int) env('PAYFAKE_CIRCUIT_WINDOW_SECONDS', 30),
        'open_seconds' => (int) env('PAYFAKE_CIRCUIT_OPEN_SECONDS', 20),
    ],
];
