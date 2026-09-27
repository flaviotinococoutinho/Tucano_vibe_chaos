<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use DateTimeImmutable;
use Logistics\Shipping\Domain\Destination\Destination;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Transition\ShippingLabel;

/**
 * The full state of a shipment in one immutable object (Memento). Persistence
 * reads and rebuilds shipments through it, so the aggregate needs no getters.
 */
final readonly class ShipmentSnapshot
{
    public function __construct(
        public ShipmentReference $reference,
        public CarrierCode $carrier,
        public FulfillmentCenterCode $origin,
        public Recipient $recipient,
        public Destination $destination,
        public Parcels $parcels,
        public ShipmentStatus $status,
        public DeliveryAttempts $attempts,
        public ?ShippingLabel $label,
        public DateTimeImmutable $createdAt,
        public int $version,
    ) {}
}
