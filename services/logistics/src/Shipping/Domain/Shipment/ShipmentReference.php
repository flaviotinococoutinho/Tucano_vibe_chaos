<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

/**
 * Who a shipment is, as every event about it says: its id, its public code, the order it
 * serves and the store it belongs to. A shipment from before the stores has no store, and
 * neither has one whose store was still unknown when it was created (ADR 0031).
 */
final readonly class ShipmentReference
{
    private function __construct(
        public ShipmentId $id,
        public TrackingCode $trackingCode,
        public OrderId $orderId,
        public ?StoreSlug $store,
    ) {}

    public static function of(ShipmentId $id, TrackingCode $trackingCode, OrderId $orderId, ?StoreSlug $store): self
    {
        return new self($id, $trackingCode, $orderId, $store);
    }
}
