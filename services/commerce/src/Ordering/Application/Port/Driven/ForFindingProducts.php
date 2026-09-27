<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Ordering\Domain\Product\CatalogProduct;
use Commerce\Ordering\Domain\Product\Sku;

/** The local copy of the catalog: checkout never calls the catalog service. */
interface ForFindingProducts
{
    /** @return array<string, CatalogProduct> keyed by SKU; unknown SKUs are left out */
    public function bySku(Sku ...$skus): array;
}
