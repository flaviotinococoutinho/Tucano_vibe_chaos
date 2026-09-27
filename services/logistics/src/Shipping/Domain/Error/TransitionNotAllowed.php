<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The transition table has no such move: no data could make it happen. */
final class TransitionNotAllowed extends DomainError
{
    public static function for(TrackingCode $shipment, ShipmentStatus $current, ShipmentStatus $target): self
    {
        return new self(sprintf('Shipment %s is %s and cannot move to %s.', $shipment, $current->value, $target->value));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
