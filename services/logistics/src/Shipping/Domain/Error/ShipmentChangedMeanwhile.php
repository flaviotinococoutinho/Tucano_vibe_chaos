<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class ShipmentChangedMeanwhile extends DomainError
{
    public static function withId(string $shipmentId, int $expectedVersion): self
    {
        return new self(sprintf('Shipment %s is no longer at version %d; someone changed it meanwhile.', $shipmentId, $expectedVersion));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
