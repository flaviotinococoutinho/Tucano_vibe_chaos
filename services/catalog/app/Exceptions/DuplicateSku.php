<?php

declare(strict_types=1);

namespace App\Exceptions;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class DuplicateSku extends DomainError
{
    public static function of(string $sku): self
    {
        return new self(sprintf('Product %s already exists.', $sku));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
