<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\Port\Driven\ForFindingProducts;
use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Product\CatalogProduct;
use Logistics\Shipping\Domain\Product\Sku;

final class InMemoryCatalog implements ForFindingProducts
{
    /** @var array<string, CatalogProduct> */
    private array $products = [];

    public function add(string $sku, int $grams, int $lengthMm, int $widthMm, int $heightMm): self
    {
        $this->products[$sku] = new CatalogProduct(Sku::of($sku), Weight::ofGrams($grams), Dimensions::ofMillimetres($lengthMm, $widthMm, $heightMm));

        return $this;
    }

    public function bySku(Sku ...$skus): array
    {
        return array_intersect_key($this->products, array_flip(array_map(static fn(Sku $sku): string => (string) $sku, $skus)));
    }
}
