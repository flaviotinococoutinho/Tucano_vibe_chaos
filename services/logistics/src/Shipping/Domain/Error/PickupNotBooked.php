<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The carrier did not answer the booking: every booking would fail the same way until it is back. */
final class PickupNotBooked extends DomainError
{
    public static function because(string $reason, ?Throwable $cause = null): self
    {
        return new self(sprintf('The carrier did not answer the pickup request: %s.', $reason), previous: $cause);
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
