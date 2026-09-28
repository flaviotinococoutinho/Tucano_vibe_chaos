<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Error;

use Commerce\Ordering\Domain\Product\Sku;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;
use Tucano\SharedKernel\Domain\ProblemType;

#[ProblemType('product-unavailable')]
final class ProductUnavailable extends DomainError
{
    public static function unknown(Sku $sku): self
    {
        return new self(sprintf('%s is not in the catalog.', $sku));
    }

    public static function discontinued(Sku $sku): self
    {
        return new self(sprintf('%s is no longer sold.', $sku));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
