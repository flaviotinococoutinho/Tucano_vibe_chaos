<?php

declare(strict_types=1);

return [
    // How long a new order holds its stock while it waits for payment.
    'reservation_minutes' => (int) env('ORDER_RESERVATION_MINUTES', 15),
];
