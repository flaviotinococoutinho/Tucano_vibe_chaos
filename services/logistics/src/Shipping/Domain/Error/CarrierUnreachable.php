<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class CarrierUnreachable extends DomainError
{
    public static function because(string $reason, ?Throwable $cause = null): self
    {
        return new self(sprintf('The carrier did not answer about the pickup: %s.', $reason), previous: $cause);
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
