<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Product\ProductStatus;
use Commerce\Ordering\Domain\Product\Sku;
use Tucano\SharedKernel\Money\Money;

/** The part of a catalog snapshot that checkout needs, with the version it came with. */
final readonly class CatalogSnapshot
{
    public function __construct(
        public string $productId,
        public Sku $sku,
        public string $name,
        public Money $price,
        public ProductStatus $status,
        public int $version,
    ) {}
}
