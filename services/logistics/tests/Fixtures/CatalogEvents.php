<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Tucano\Messaging\Kafka\ReceivedMessage;

/** Product snapshots as the catalog publishes them on catalog.products.v1 (contracts/events/catalog.product.snapshot). */
final class CatalogEvents
{
    public const string PRODUCT = '01999a1f-0a1b-7c2d-8e3f-4a5b6c7d8e9f';

    private function __construct() {}

    /** @param array<string, mixed> $data fields to change in the data of the event */
    public static function snapshot(array $data = [], string $type = 'tucano.catalog.product.snapshot'): string
    {
        return json_encode([
            'specversion' => '1.0',
            'id' => '01999a20-5b6c-7d8e-9f0a-1b2c3d4e5f60',
            'source' => '/catalog',
            'type' => $type,
            'subject' => self::PRODUCT,
            'time' => '2026-09-27T12:00:00.000Z',
            'datacontenttype' => 'application/json',
            'correlationid' => 'req-9#1',
            'data' => [
                'productId' => self::PRODUCT,
                'sku' => 'BOOK-DDD-001',
                'name' => 'Domain-Driven Design',
                'status' => 'active',
                'category' => 'books',
                'price' => ['amount' => 18990, 'currency' => 'BRL'],
                'weightGrams' => 1100,
                'dimensions' => ['lengthMm' => 240, 'widthMm' => 170, 'heightMm' => 40],
                'version' => 3,
                'updatedAt' => '2026-09-27T12:00:00.000Z',
                ...$data,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public static function message(string $payload): ReceivedMessage
    {
        return new ReceivedMessage('catalog.products.v1', 1, 42, self::PRODUCT, $payload);
    }
}
