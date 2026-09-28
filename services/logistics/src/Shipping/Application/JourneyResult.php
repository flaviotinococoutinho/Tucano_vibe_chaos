<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

/** How a round of UC-SHP-12 ended for one shipment. */
enum JourneyResult: string
{
    /** The history had steps the shipment was missing, and they are in now. */
    case CaughtUp = 'caught_up';

    /** Every event of the history was already in: the journey is just slow, or the carrier went silent. */
    case UpToDate = 'up_to_date';

    /** The carrier has no pickup for the shipment: never booked, or older than what the carrier keeps. */
    case UnknownToCarrier = 'unknown_to_carrier';

    /** The carrier did not answer; the shipment is asked about again when it is quiet again. */
    case CarrierUnreachable = 'carrier_unreachable';

    /** The machine refused a step of the history; the steps before it stay in, and a person has to look. */
    case Stopped = 'stopped';
}
