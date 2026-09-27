<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;

/**
 * Data of the tucano.catalog.product.snapshot event: the full state of the product,
 * not what changed (event-carried state transfer). The contract lives in
 * contracts/events/catalog.product.snapshot.schema.json.
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
            'category' => $record['category'],
            'price' => $record['price'],
            'weightGrams' => $record['weightGrams'],
            'dimensions' => $record['dimensions'],
            'version' => $record['version'],
            'updatedAt' => $record['updatedAt'],
        ];
    }
}
