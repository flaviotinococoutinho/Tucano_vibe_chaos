<?php

declare(strict_types=1);

namespace App\Exceptions;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The address names a store the platform does not host. */
final class StoreNotFound extends DomainError
{
    public static function withSlug(string $slug): self
    {
        return new self(sprintf('Store %s does not exist.', $slug));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
