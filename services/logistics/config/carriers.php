<?php

declare(strict_types=1);

return [
    // CarrierFake, the carriers of the lab, through Toxiproxy (the carriers proxy).
    'url' => env('CARRIERS_URL', 'http://toxiproxy:14002'),
    // The whole call, and the part of it spent connecting.
    'timeout_ms' => (int) env('CARRIERS_TIMEOUT_MS', 2000),
    'connect_timeout_ms' => (int) env('CARRIERS_CONNECT_TIMEOUT_MS', 500),
    // Shared with CarrierFake to sign its webhooks.
    'webhook_secret' => env('CARRIERS_WEBHOOK_SECRET', 'whsec_local_carriers'),
    // A signed webhook older (or newer) than this is refused as a replay.
    'webhook_tolerance_seconds' => (int) env('CARRIERS_WEBHOOK_TOLERANCE_SECONDS', 300),
];
