<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** Optimistic concurrency: someone changed the product after the client (or this request) read it. */
final class StaleVersion extends DomainError
{
    public static function expected(Product $current, int $expectedVersion): self
    {
        return new self(sprintf(
            'Product %s is at version %d, not %d. Read it again before changing it.',
            $current->sku,
            $current->version,
            $expectedVersion,
        ));
    }

    public static function changedMeanwhile(Product $read): self
    {
        return new self(sprintf(
            'Product %s changed after version %d was read. Read it again and retry.',
            $read->sku,
            $read->version,
        ));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
