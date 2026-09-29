<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;

/**
 * Data of the tucano.catalog.product.snapshot event: the full state of the product,
 * not what changed (event-carried state transfer). The contract lives in
 * contracts/events/catalog.product.snapshot.schema.json. The store always goes: every
 * product has one, and consumers learn from it which store sells the SKU.
 */
final class ProductSnapshot
{
    public const string TYPE = 'tucano.catalog.product.snapshot';

    /**
     * @return array{
     *     productId: string,
     *     sku: string,
     *     name: string,
     *     status: string,
     *     store: string,
     *     category: string,
     *     price: array{amount: int, currency: string},
     *     weightGrams: int,
     *     dimensions: array{lengthMm: int, widthMm: int, heightMm: int},
     *     version: int,
     *     updatedAt: string
     * }
     */
    public static function of(Product $product): array
    {
        $record = $product->toArray();

        return [
            'productId' => $record['id'],
            'sku' => $record['sku'],
            'name' => $record['name'],
            'status' => $record['status'],
            'store' => $record['store'],
            'category' => $record['category'],
            'price' => $record['price'],
            'weightGrams' => $record['weightGrams'],
            'dimensions' => $record['dimensions'],
            'version' => $record['version'],
            'updatedAt' => $record['updatedAt'],
        ];
    }
}
