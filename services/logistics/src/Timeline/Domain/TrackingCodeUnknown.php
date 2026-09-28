<?php

declare(strict_types=1);

namespace Logistics\Timeline\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class TrackingCodeUnknown extends DomainError
{
    public static function code(string $trackingCode): self
    {
        return new self(sprintf('No shipment is tracked as %s, or its news has not reached the tracking page yet.', $trackingCode));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
