<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Domain\Product\CatalogProduct;
use Logistics\Shipping\Domain\Product\Sku;

/** The local copy of the catalog: creating a shipment never calls the catalog service. */
interface ForFindingProducts
{
    /** @return array<string, CatalogProduct> keyed by SKU; unknown SKUs are left out */
    public function bySku(Sku ...$skus): array;
}
