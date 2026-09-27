<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Event;

final readonly class ShipmentPickedUp extends ShipmentEvent
{
    protected function details(): array
    {
        return [];
    }
}
