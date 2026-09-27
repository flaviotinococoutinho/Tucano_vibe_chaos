<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

enum CancellationReason: string
{
    case PaymentDeclined = 'payment_declined';
    case ReservationExpired = 'reservation_expired';
    case CustomerRequest = 'customer_request';
}
