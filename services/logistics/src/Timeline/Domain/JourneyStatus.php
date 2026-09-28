<?php

declare(strict_types=1);

namespace Logistics\Timeline\Domain;

/**
 * The statuses of a shipment as the read side sees them, one per event type of
 * logistics.shipments.v2. The write side has its own ShipmentStatus; this one
 * only has to name what the customer is told.
 */
enum JourneyStatus: string
{
    case Created = 'created';
    case ReadyForPickup = 'ready_for_pickup';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case Returning = 'returning';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
}
