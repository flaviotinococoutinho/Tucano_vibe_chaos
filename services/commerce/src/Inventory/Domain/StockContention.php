<?php

declare(strict_types=1);

namespace Commerce\Inventory\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The stock kept changing under an optimistic reservation until it gave up. Trying again may work. */
final class StockContention extends DomainError
{
    public static function on(string $sku, string $fulfillmentCenter, int $attempts): self
    {
        return new self(sprintf('The stock of %s in %s changed %d times while it was being reserved; try again.', $sku, $fulfillmentCenter, $attempts));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
