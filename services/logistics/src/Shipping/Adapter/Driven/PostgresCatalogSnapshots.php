<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Illuminate\Database\ConnectionInterface;
use Logistics\Shipping\Application\CatalogSnapshot;
use Logistics\Shipping\Application\Port\Driven\ForFindingProducts;
use Logistics\Shipping\Application\Port\Driven\ForStoringCatalogCopies;
use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Product\CatalogProduct;
use Logistics\Shipping\Domain\Product\Sku;

/** product_snapshots: the copy of catalog.products.v1 that the catalog-sync consumer writes and shipping reads. */
final readonly class PostgresCatalogSnapshots implements ForFindingProducts, ForStoringCatalogCopies
{
    public function __construct(private ConnectionInterface $connection) {}

    public function bySku(Sku ...$skus): array
    {
        $rows = $this->connection->table('product_snapshots')
            ->whereIn('sku', array_map(static fn(Sku $sku): string => (string) $sku, $skus))
            ->get(['sku', 'weight_grams', 'length_mm', 'width_mm', 'height_mm']);

        $products = [];
        foreach ($rows as $row) {
            $products[(string) $row->sku] = new CatalogProduct(
                Sku::of((string) $row->sku),
                Weight::ofGrams((int) $row->weight_grams),
                Dimensions::ofMillimetres((int) $row->length_mm, (int) $row->width_mm, (int) $row->height_mm),
            );
        }

        return $products;
    }

    public function saveIfNewer(CatalogSnapshot $snapshot): bool
    {
        // One statement: the version check and the write cannot interleave with another consumer.
        return $this->connection->affectingStatement(<<<'SQL'
            INSERT INTO product_snapshots (product_id, sku, name, weight_grams, length_mm, width_mm, height_mm, catalog_version, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, now())
            ON CONFLICT (product_id) DO UPDATE
                SET sku = EXCLUDED.sku, name = EXCLUDED.name, weight_grams = EXCLUDED.weight_grams,
                    length_mm = EXCLUDED.length_mm, width_mm = EXCLUDED.width_mm, height_mm = EXCLUDED.height_mm,
                    catalog_version = EXCLUDED.catalog_version, synced_at = EXCLUDED.synced_at
                WHERE product_snapshots.catalog_version < EXCLUDED.catalog_version
            SQL, [
            $snapshot->productId,
            (string) $snapshot->sku,
            $snapshot->name,
            $snapshot->weight->grams(),
            $snapshot->dimensions->lengthMm,
            $snapshot->dimensions->widthMm,
            $snapshot->dimensions->heightMm,
            $snapshot->version,
        ]) === 1;
    }
}
