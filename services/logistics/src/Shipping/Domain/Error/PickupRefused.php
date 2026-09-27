<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The carrier answered and refused the booking: asking again would get the same answer. */
final class PickupRefused extends DomainError
{
    public static function because(string $reason): self
    {
        return new self(sprintf('The carrier refused the pickup: %s', $reason));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::InvalidInput;
    }
}
