<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class InvalidPayment extends DomainError
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
