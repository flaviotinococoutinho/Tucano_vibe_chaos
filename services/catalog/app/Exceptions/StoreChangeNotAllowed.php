<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;

/** A product is born in a store and stays there (docs/adr/0031-a-store-is-a-tenant.md). */
final class StoreChangeNotAllowed extends InvalidField
{
    public static function of(Product $product, string $store): self
    {
        return new self(sprintf(
            'Product %s belongs to store %s and cannot move to %s.',
            $product->sku,
            $product->store,
            $store,
        ));
    }

    public function field(): string
    {
        return 'store';
    }
}
