<?php

declare(strict_types=1);

namespace App\Exceptions;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class UnknownCategory extends DomainError
{
    public static function withSlug(string $slug): self
    {
        return new self(sprintf('Category "%s" does not exist.', $slug));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::InvalidInput;
    }
}
