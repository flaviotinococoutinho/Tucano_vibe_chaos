<?php

declare(strict_types=1);

return [
    // CarrierFake, the carriers of the lab, through Toxiproxy (the carriers proxy).
    'url' => env('CARRIERS_URL', 'http://toxiproxy:14002'),
    'timeout_ms' => (int) env('CARRIERS_TIMEOUT_MS', 2000),
    // Shared with CarrierFake to sign its webhooks.
    'webhook_secret' => env('CARRIERS_WEBHOOK_SECRET', 'whsec_local_carriers'),
    'reconciliation' => [
        // A shipment in the hands of a carrier is compared with the carrier's history after this long without news (UC-SHP-12).
        'quiet_seconds' => (int) env('CARRIERS_RECONCILIATION_QUIET_SECONDS', 60),
    ],
];
