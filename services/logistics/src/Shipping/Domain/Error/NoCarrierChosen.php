<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/**
 * Carrier selection gave the shipment no carrier: none takes the parcels, or the
 * origin is unknown there. The refusal keeps its reason and its category, so the
 * order intake treats it exactly as it would treat the original.
 */
final class NoCarrierChosen extends DomainError
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
