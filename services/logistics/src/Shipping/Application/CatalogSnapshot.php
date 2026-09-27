<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Product\Sku;

/** The part of a catalog snapshot that shipping needs, with the version it came with. */
final readonly class CatalogSnapshot
{
    public function __construct(
        public string $productId,
        public Sku $sku,
        public string $name,
        public Weight $weight,
        public Dimensions $dimensions,
        public int $version,
    ) {}
}
