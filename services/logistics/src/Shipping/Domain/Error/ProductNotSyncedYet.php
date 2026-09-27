<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Logistics\Shipping\Domain\Product\Sku;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/**
 * The local copy of the catalog has not seen this product yet. It usually
 * arrives a moment later, so the one who asked may try again.
 */
final class ProductNotSyncedYet extends DomainError
{
    public static function sku(Sku $sku): self
    {
        return new self(sprintf('There is no weight and size for %s yet; the catalog copy has not caught up.', $sku));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
