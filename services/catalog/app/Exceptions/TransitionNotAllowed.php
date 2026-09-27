<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;
use App\Models\ProductStatus;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class TransitionNotAllowed extends DomainError
{
    public static function of(Product $product, ProductStatus $next): self
    {
        return new self(sprintf(
            'Product %s cannot move from %s to %s.',
            $product->sku,
            $product->status->value,
            $next->value,
        ));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
