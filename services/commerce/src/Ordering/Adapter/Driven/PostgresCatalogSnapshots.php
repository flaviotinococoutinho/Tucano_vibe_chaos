<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driven;

use Commerce\Ordering\Application\CatalogSnapshot;
use Commerce\Ordering\Application\Port\Driven\ForFindingProducts;
use Commerce\Ordering\Application\Port\Driven\ForStoringCatalogCopies;
use Commerce\Ordering\Domain\Product\CatalogProduct;
use Commerce\Ordering\Domain\Product\ProductStatus;
use Commerce\Ordering\Domain\Product\Sku;
use Illuminate\Database\ConnectionInterface;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/** product_snapshots: the copy of catalog.products.v1 that checkout reads and the catalog-sync consumer writes. */
final readonly class PostgresCatalogSnapshots implements ForFindingProducts, ForStoringCatalogCopies
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

    public function saveIfNewer(CatalogSnapshot $snapshot): bool
    {
        // One statement: the version check and the write cannot interleave with another consumer.
        return $this->connection->affectingStatement(<<<'SQL'
            INSERT INTO product_snapshots (product_id, sku, name, price_cents, currency, status, catalog_version, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, now())
            ON CONFLICT (product_id) DO UPDATE
                SET sku = EXCLUDED.sku, name = EXCLUDED.name, price_cents = EXCLUDED.price_cents,
                    currency = EXCLUDED.currency, status = EXCLUDED.status,
                    catalog_version = EXCLUDED.catalog_version, synced_at = EXCLUDED.synced_at
                WHERE product_snapshots.catalog_version < EXCLUDED.catalog_version
            SQL, [
            $snapshot->productId,
            (string) $snapshot->sku,
            $snapshot->name,
            $snapshot->price->cents(),
            $snapshot->price->currency()->code(),
            $snapshot->status->value,
            $snapshot->version,
        ]) === 1;
    }
}
