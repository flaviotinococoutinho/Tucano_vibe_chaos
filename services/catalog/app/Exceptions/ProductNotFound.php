<?php

declare(strict_types=1);

namespace App\Exceptions;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class ProductNotFound extends DomainError
{
    public static function withSku(string $sku): self
    {
        return new self(sprintf('Product %s does not exist.', $sku));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
