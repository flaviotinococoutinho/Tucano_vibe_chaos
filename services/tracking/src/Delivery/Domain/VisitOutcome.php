<?php

declare(strict_types=1);

namespace Tracking\Delivery\Domain;

enum VisitOutcome: string
{
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
}
