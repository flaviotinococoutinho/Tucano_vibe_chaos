<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Event;

final readonly class ShipmentCancelled extends ShipmentEvent
{
    protected function details(): array
    {
        return ['previousStatus' => $this->transition->from?->value, 'reason' => $this->transition->reason];
    }
}
