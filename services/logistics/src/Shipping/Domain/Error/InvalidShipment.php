<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class InvalidShipment extends DomainError
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::InvalidInput;
    }
}
