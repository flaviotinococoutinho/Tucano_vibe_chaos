<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Domain\Shipment\DeliveryAttempt;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\TrackingCode;

final class InMemoryShipments implements ForStoringShipments
{
    /** @var array<string, Shipment> keyed by order id */
    private array $shipments = [];

    /** @var list<DeliveryAttempt> the visits saved, oldest first */
    public private(set) array $visits = [];

    public function add(Shipment $shipment): void
    {
        $shipment->releaseTransitions();
        $this->shipments[$shipment->toSnapshot()->reference->orderId->toString()] = $shipment;
    }

    public function forOrder(OrderId $order): ?Shipment
    {
        return $this->shipments[$order->toString()] ?? null;
    }

    public function withId(ShipmentId $shipment): ?Shipment
    {
        foreach ($this->shipments as $stored) {
            if ($stored->toSnapshot()->reference->id->equals($shipment)) {
                return $stored;
            }
        }

        return null;
    }

    public function withTrackingCode(TrackingCode $trackingCode): ?Shipment
    {
        foreach ($this->shipments as $stored) {
            if ((string) $stored->toSnapshot()->reference->trackingCode === (string) $trackingCode) {
                return $stored;
            }
        }

        return null;
    }

    public function save(Shipment $shipment): void
    {
        array_push($this->visits, ...$shipment->releaseVisits());
        $this->add($shipment);
    }

    public function count(): int
    {
        return count($this->shipments);
    }
}
