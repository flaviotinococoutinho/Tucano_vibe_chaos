<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

enum CancellationOutcome
{
    case Cancelled;
    case NoShipment;
    case Repeated;
}
