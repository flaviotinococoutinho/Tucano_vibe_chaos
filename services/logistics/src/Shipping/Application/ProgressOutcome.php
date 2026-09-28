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

    /** The shipment already went past what the event tells, like a hub scan after the parcels left for the door: handled, nothing to move. */
    case Obsolete = 'obsolete';
}
