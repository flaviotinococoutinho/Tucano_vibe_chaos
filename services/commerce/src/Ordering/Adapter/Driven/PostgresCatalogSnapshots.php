<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driven;

use Commerce\Ordering\Application\Port\Driven\ForFindingProducts;
use Commerce\Ordering\Domain\Product\CatalogProduct;
use Commerce\Ordering\Domain\Product\ProductStatus;
use Commerce\Ordering\Domain\Product\Sku;
use Illuminate\Database\ConnectionInterface;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/** Reads product_snapshots, the copy of catalog.products.v1 kept by the catalog-sync consumer. */
final readonly class PostgresCatalogSnapshots implements ForFindingProducts
{
    public function __construct(private ConnectionInterface $connection) {}

    public function bySku(Sku ...$skus): array
    {
        $rows = $this->connection->table('product_snapshots')
            ->whereIn('sku', array_map(static fn(Sku $sku): string => (string) $sku, $skus))
            ->get(['sku', 'name', 'price_cents', 'currency', 'status']);

        $products = [];
        foreach ($rows as $row) {
            $products[(string) $row->sku] = new CatalogProduct(
                Sku::of((string) $row->sku),
                (string) $row->name,
                Money::of((int) $row->price_cents, Currency::fromCode((string) $row->currency)),
                ProductStatus::from((string) $row->status),
            );
        }

        return $products;
    }
}
