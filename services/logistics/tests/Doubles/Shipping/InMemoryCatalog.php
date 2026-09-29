<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\Port\Driven\ForFindingProducts;
use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Product\CatalogProduct;
use Logistics\Shipping\Domain\Product\Sku;
use Logistics\Shipping\Domain\Shipment\StoreSlug;

final class InMemoryCatalog implements ForFindingProducts
{
    /** @var array<string, CatalogProduct> */
    private array $products = [];

    /** @param ?string $store null for a product whose store the copy does not know yet */
    public function add(string $sku, int $grams, int $lengthMm, int $widthMm, int $heightMm, ?string $store = null): self
    {
        $this->products[$sku] = new CatalogProduct(
            Sku::of($sku),
            $store === null ? null : StoreSlug::of($store),
            Weight::ofGrams($grams),
            Dimensions::ofMillimetres($lengthMm, $widthMm, $heightMm),
        );

        return $this;
    }

    public function bySku(Sku ...$skus): array
    {
        return array_intersect_key($this->products, array_flip(array_map(static fn(Sku $sku): string => (string) $sku, $skus)));
    }
}
