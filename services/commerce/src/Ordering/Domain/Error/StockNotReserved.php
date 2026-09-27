<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Error;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/**
 * Inventory could not hold the stock of the order: not enough units, or too
 * much contention on them. The refusal keeps its reason and its category, so
 * the caller answers exactly as it would for the original.
 */
final class StockNotReserved extends DomainError
{
    private ErrorCategory $category = ErrorCategory::Conflict;

    public static function because(DomainError $refusal): self
    {
        $error = new self($refusal->getMessage(), previous: $refusal);
        $error->category = $refusal->category();

        return $error;
    }

    public function category(): ErrorCategory
    {
        return $this->category;
    }
}
