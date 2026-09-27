<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

/** Who a shipment is, as every event about it says: its id, its public code and the order it serves. */
final readonly class ShipmentReference
{
    public function __construct(
        public ShipmentId $id,
        public TrackingCode $trackingCode,
        public OrderId $orderId,
    ) {}
}
