<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Transition;

use Logistics\Shipping\Domain\Shipment\DeliveryAttempts;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;

/** A move the table allows, on its way through the guard chain. */
final readonly class TransitionRequest
{
    public function __construct(
        public ShipmentStatus $target,
        public DeliveryAttempts $attempts,
        public Evidence $evidence,
    ) {}

    public function leadsTo(ShipmentStatus $status): bool
    {
        return $this->target === $status;
    }
}
