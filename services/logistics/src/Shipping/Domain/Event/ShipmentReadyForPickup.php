<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Event;

final readonly class ShipmentReadyForPickup extends ShipmentEvent
{
    protected function details(): array
    {
        return [];
    }
}
