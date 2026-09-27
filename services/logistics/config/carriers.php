<?php

declare(strict_types=1);

return [
    // CarrierFake, the carriers of the lab, through Toxiproxy (the carriers proxy).
    'url' => env('CARRIERS_URL', 'http://toxiproxy:14002'),
    'timeout_ms' => (int) env('CARRIERS_TIMEOUT_MS', 2000),
    // Shared with CarrierFake to sign its webhooks.
    'webhook_secret' => env('CARRIERS_WEBHOOK_SECRET', 'whsec_local_carriers'),
];
