<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

enum ProgressOutcome: string
{
    case Applied = 'applied';

    /** The same event arrived again: the inbox already has it. */
    case Duplicate = 'duplicate';

    /** The tracking code is not a shipment of ours. */
    case UnknownShipment = 'unknown_shipment';
}
