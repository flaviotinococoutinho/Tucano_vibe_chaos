<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Error;

use Commerce\Ordering\Domain\Product\Sku;
use Commerce\Ordering\Domain\Store\StoreSlug;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/**
 * An order is of one store only (ADR 0031), and some of its items are not products of that
 * store: they belong to another one, or the local copy of the catalog does not know their store
 * yet. The refusal names each of them by its place in the order, counted from 0, so the caller
 * points at the item itself.
 */
final class ProductOfAnotherStore extends DomainError
{
    /** @var array<int, string> why each refused item is refused, by its place in the order */
    public private(set) array $refusals = [];

    /** @param non-empty-array<int, Sku> $skus the refused items, by their place in the order */
    public static function in(StoreSlug $store, array $skus): self
    {
        $refusals = array_map(static fn(Sku $sku): string => sprintf('%s is not a product of %s.', $sku, $store), $skus);
        $error = new self(implode(' ', $refusals));
        $error->refusals = $refusals;

        return $error;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::InvalidInput;
    }
}
