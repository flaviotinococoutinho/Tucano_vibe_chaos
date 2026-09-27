<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

/** How a visit to the address ended, as delivery_attempts stores it. */
enum AttemptOutcome: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Refused = 'refused';
}
