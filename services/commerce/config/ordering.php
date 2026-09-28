<?php

declare(strict_types=1);

return [
    // How long a new order holds its stock while it waits for payment.
    'reservation_minutes' => (int) env('ORDERS_RESERVATION_MINUTES', 15),
    // UC-ORD-03: the pause of the expiry worker when no order is due, and after a failed round.
    'expiry' => [
        'idle_pause_ms' => (int) env('ORDERS_EXPIRY_IDLE_PAUSE_MS', 5000),
        'failure_pause_ms' => (int) env('ORDERS_EXPIRY_FAILURE_PAUSE_MS', 2000),
    ],
];
