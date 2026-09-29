<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driven;

use Commerce\Ordering\Application\CatalogSnapshot;
use Commerce\Ordering\Application\Port\Driven\ForFindingProducts;
use Commerce\Ordering\Application\Port\Driven\ForStoringCatalogCopies;
use Commerce\Ordering\Domain\Product\CatalogProduct;
use Commerce\Ordering\Domain\Product\ProductStatus;
use Commerce\Ordering\Domain\Product\Sku;
use Commerce\Ordering\Domain\Store\StoreSlug;
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
            ->get(['sku', 'name', 'price_cents', 'currency', 'status', 'store']);

        $products = [];
        foreach ($rows as $row) {
            $products[(string) $row->sku] = CatalogProduct::of(
                Sku::of((string) $row->sku),
                (string) $row->name,
                Money::of((int) $row->price_cents, Currency::fromCode((string) $row->currency)),
                ProductStatus::from((string) $row->status),
                $row->store === null ? null : StoreSlug::of((string) $row->store),
            );
        }

        return $products;
    }

    public function saveIfNewer(CatalogSnapshot $snapshot): bool
    {
        // One statement: the version check and the write cannot interleave with another consumer.
        // The store of a product never changes (ADR 0031), so the same version may bring the store
        // of a product from before the stores, and a snapshot that leaves it out keeps the one known.
        return $this->connection->affectingStatement(<<<'SQL'
            INSERT INTO product_snapshots (product_id, sku, name, price_cents, currency, status, store, catalog_version, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, now())
            ON CONFLICT (product_id) DO UPDATE
                SET sku = EXCLUDED.sku, name = EXCLUDED.name, price_cents = EXCLUDED.price_cents,
                    currency = EXCLUDED.currency, status = EXCLUDED.status,
                    store = COALESCE(EXCLUDED.store, product_snapshots.store),
                    catalog_version = EXCLUDED.catalog_version, synced_at = EXCLUDED.synced_at
                WHERE product_snapshots.catalog_version < EXCLUDED.catalog_version
                   OR (product_snapshots.catalog_version = EXCLUDED.catalog_version
                       AND product_snapshots.store IS NULL AND EXCLUDED.store IS NOT NULL)
            SQL, [
            $snapshot->productId,
            (string) $snapshot->sku,
            $snapshot->name,
            $snapshot->price->cents(),
            $snapshot->price->currency()->code(),
            $snapshot->status->value,
            $snapshot->store === null ? null : (string) $snapshot->store,
            $snapshot->version,
        ]) === 1;
    }
}
