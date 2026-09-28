<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\CarrierEvent;
use Logistics\Shipping\Application\Port\Driven\ForTrackingPickups;
use Logistics\Shipping\Domain\Error\CarrierUnreachable;
use Logistics\Shipping\Domain\Shipment\ShipmentId;

/** A carrier whose history the test writes, and that can stop answering. */
final class ScriptedTracking implements ForTrackingPickups
{
    /** @var array<string, list<CarrierEvent>> keyed by shipment id */
    private array $histories = [];

    private bool $down = false;

    public function knows(ShipmentId $shipment, CarrierEvent ...$history): self
    {
        $this->histories[$shipment->toString()] = array_values($history);

        return $this;
    }

    public function goesDown(): void
    {
        $this->down = true;
    }

    public function eventsOf(ShipmentId $shipment): array
    {
        if ($this->down) {
            throw CarrierUnreachable::because('connection refused');
        }

        return $this->histories[$shipment->toString()] ?? [];
    }
}
