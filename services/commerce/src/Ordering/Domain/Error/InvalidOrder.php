<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Error;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class InvalidOrder extends DomainError
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
