<?php

declare(strict_types=1);

return [
    'payfake' => [
        // Through Toxiproxy (the payfake proxy), so chaos experiments can slow the provider down.
        'url' => env('PAYFAKE_URL', 'http://toxiproxy:14001'),
        // The whole call, and the part of it spent connecting.
        'timeout_ms' => (int) env('PAYFAKE_TIMEOUT_MS', 2000),
        'connect_timeout_ms' => (int) env('PAYFAKE_CONNECT_TIMEOUT_MS', 500),
        // Shared with PayFake to sign its webhooks.
        'webhook_secret' => env('PAYFAKE_WEBHOOK_SECRET', 'whsec_local_payfake'),
        // A signed webhook older (or newer) than this is refused as a replay.
        'webhook_tolerance_seconds' => (int) env('PAYFAKE_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],
    // UC-PAY-03: the payments still missing the provider's final word.
    'reconciliation' => [
        // A payment is looked at after this long without news.
        'quiet_seconds' => (int) env('PAYMENTS_RECONCILIATION_QUIET_SECONDS', 60),
        // A charge the provider took and no longer shows gets this long to turn up; then the payment fails as charge_lost.
        'lost_charge_after_seconds' => (int) env('PAYMENTS_RECONCILIATION_LOST_CHARGE_AFTER_SECONDS', 3600),
        // The pause of the reconciler when no payment is due, and after a failed round.
        'idle_pause_ms' => (int) env('PAYMENTS_RECONCILIATION_IDLE_PAUSE_MS', 5000),
        'failure_pause_ms' => (int) env('PAYMENTS_RECONCILIATION_FAILURE_PAUSE_MS', 2000),
    ],
    // The circuit breaker in front of PayFake, shared by every process through Redis.
    'circuit' => [
        // This many failures within the window open the circuit.
        'failure_threshold' => (int) env('PAYFAKE_CIRCUIT_FAILURE_THRESHOLD', 5),
        'window_seconds' => (int) env('PAYFAKE_CIRCUIT_WINDOW_SECONDS', 30),
        // Open for this long; then one trial call at a time, holding its lock at most this long.
        'open_seconds' => (int) env('PAYFAKE_CIRCUIT_OPEN_SECONDS', 20),
        'trial_seconds' => (int) env('PAYFAKE_CIRCUIT_TRIAL_SECONDS', 10),
    ],
];
