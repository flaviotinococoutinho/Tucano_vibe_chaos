<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** An address nobody could deliver to as it was written: whoever sent it has to fix it. */
final class InvalidAddress extends DomainError
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
