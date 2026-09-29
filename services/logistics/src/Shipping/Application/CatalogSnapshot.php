<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Product\Sku;
use Logistics\Shipping\Domain\Shipment\StoreSlug;

/**
 * The part of a catalog snapshot that shipping needs, with the version it came with. A
 * snapshot from before the stores has no store (ADR 0031).
 */
final readonly class CatalogSnapshot
{
    public function __construct(
        public string $productId,
        public Sku $sku,
        public ?StoreSlug $store,
        public string $name,
        public Weight $weight,
        public Dimensions $dimensions,
        public int $version,
    ) {}
}
