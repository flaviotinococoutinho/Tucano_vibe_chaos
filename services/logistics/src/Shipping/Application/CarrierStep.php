<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

/** What a carrier says happened to the parcels: one step per kind of event, each landing on one use case, UC-SHP-04 to 08. */
enum CarrierStep: string
{
    case PickedUp = 'picked_up';
    case HubScanned = 'hub_scanned';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case Returning = 'returning';
    case Returned = 'returned';
}
