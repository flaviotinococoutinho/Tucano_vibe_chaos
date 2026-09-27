<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Product;

use Commerce\Ordering\Domain\Error\ProductUnavailable;
use Tucano\SharedKernel\Money\Money;

/** What Ordering knows about a product: the local snapshot of the catalog. */
final readonly class CatalogProduct
{
    public function __construct(
        public Sku $sku,
        public string $name,
        public Money $price,
        public ProductStatus $status,
    ) {}

    public function assertSellable(): void
    {
        if ($this->status === ProductStatus::Discontinued) {
            throw ProductUnavailable::discontinued($this->sku);
        }
    }
}
