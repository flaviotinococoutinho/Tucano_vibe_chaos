<?php

declare(strict_types=1);

return [
    // UC-SHP-13: a shipment with a carrier and no new step for this long is stalled.
    'stalled' => [
        'after_seconds' => (int) env('JOURNEYS_STALLED_AFTER_SECONDS', 3600),
        // How often the watch runs its analytical read and, when it finds any, raises the alert.
        'watch_every_seconds' => (int) env('JOURNEYS_STALLED_WATCH_EVERY_SECONDS', 900),
        // At most this many shipments listed in one alert; the total goes in the subject.
        'alert_limit' => (int) env('JOURNEYS_STALLED_ALERT_LIMIT', 50),
        // The same stalled shipments are not alerted again before this long; a new one is alerted right away.
        'alert_repeat_seconds' => (int) env('JOURNEYS_STALLED_ALERT_REPEAT_SECONDS', 14400),
        // The analytical read gives up after this long, so it never holds the primary for the webhooks.
        'query_timeout_ms' => (int) env('JOURNEYS_STALLED_QUERY_TIMEOUT_MS', 5000),
        // How long the watch waits after a failed round (the database away, say) before trying again.
        'failure_pause_ms' => (int) env('JOURNEYS_STALLED_WATCH_FAILURE_PAUSE_MS', 10000),
    ],
    // Who gets the alerts of the operation by e-mail (MAIL_* says through which server).
    'alerts' => [
        'email_to' => env('ALERTS_EMAIL_TO', 'ops@tucano.local'),
    ],
];
