<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use DateTimeImmutable;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Domain\Shipment\DeliveryAttempt;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Logistics\Shipping\Domain\Shipment\TrackingCode;

final class InMemoryShipments implements ForStoringShipments
{
    /** @var array<string, Shipment> keyed by order id */
    private array $shipments = [];

    /** @var list<DeliveryAttempt> the visits saved, oldest first */
    public private(set) array $visits = [];

    /** @var array<string, DateTimeImmutable> when each shipment was last touched, keyed by order id */
    private array $touchedAt = [];

    public function add(Shipment $shipment): void
    {
        $shipment->releaseTransitions();
        $snapshot = $shipment->toSnapshot();
        $this->shipments[$snapshot->reference->orderId->toString()] = $shipment;
        $this->touchedAt[$snapshot->reference->orderId->toString()] ??= $snapshot->createdAt;
    }

    /** The test says how long ago the last news came. */
    public function touch(Shipment $shipment, DateTimeImmutable $at): void
    {
        $this->touchedAt[$shipment->toSnapshot()->reference->orderId->toString()] = $at;
    }

    public function claimQuiet(DateTimeImmutable $quietSince, DateTimeImmutable $now): ?ShipmentReference
    {
        $quiet = array_filter(
            $this->shipments,
            fn(Shipment $shipment, string $order): bool => $shipment->toSnapshot()->status->awaitsCarrier() && $this->touchedAt[$order] <= $quietSince,
            ARRAY_FILTER_USE_BOTH,
        );
        uksort($quiet, fn(string $a, string $b): int => $this->touchedAt[$a] <=> $this->touchedAt[$b]);
        $order = array_key_first($quiet);
        if ($order === null) {
            return null;
        }
        $this->touchedAt[$order] = $now;

        return $quiet[$order]->toSnapshot()->reference;
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
