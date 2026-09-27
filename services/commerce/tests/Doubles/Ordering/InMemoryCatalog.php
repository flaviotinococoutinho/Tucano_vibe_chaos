<?php

declare(strict_types=1);

namespace Tests\Doubles\Ordering;

use Commerce\Ordering\Application\Port\Driven\ForFindingProducts;
use Commerce\Ordering\Domain\Product\CatalogProduct;
use Commerce\Ordering\Domain\Product\Sku;

final readonly class InMemoryCatalog implements ForFindingProducts
{
    /** @var array<string, CatalogProduct> */
    private array $products;

    public function __construct(CatalogProduct ...$products)
    {
        $bySku = [];
        foreach ($products as $product) {
            $bySku[(string) $product->sku] = $product;
        }
        $this->products = $bySku;
    }

    public function bySku(Sku ...$skus): array
    {
        return array_intersect_key($this->products, array_flip(array_map(static fn(Sku $sku): string => (string) $sku, $skus)));
    }
}
