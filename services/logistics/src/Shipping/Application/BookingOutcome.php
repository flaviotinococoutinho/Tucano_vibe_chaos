<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

enum BookingOutcome: string
{
    case Booked = 'booked';

    /** The shipment is no longer waiting for pickup (cancelled, or already collected). */
    case NotNeeded = 'not_needed';
}
