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

    'reconciliation' => [
        // A payment without the provider's final word is looked at after this long without news.
        'quiet_seconds' => (int) env('PAYMENT_RECONCILIATION_QUIET_SECONDS', 60),
        // A charge the provider took and no longer shows gets this long to turn up; then the payment fails as charge_lost.
        'lost_charge_after_seconds' => (int) env('PAYMENTS_RECONCILIATION_LOST_CHARGE_AFTER_SECONDS', 3600),
    ],

    'circuit' => [
        'failure_threshold' => (int) env('PAYFAKE_CIRCUIT_FAILURES', 5),
        'window_seconds' => (int) env('PAYFAKE_CIRCUIT_WINDOW_SECONDS', 30),
        'open_seconds' => (int) env('PAYFAKE_CIRCUIT_OPEN_SECONDS', 20),
    ],
];
