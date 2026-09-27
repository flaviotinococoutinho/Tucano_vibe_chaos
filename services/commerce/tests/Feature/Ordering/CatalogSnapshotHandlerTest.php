<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use Commerce\Ordering\Adapter\Driving\Kafka\CatalogSnapshotHandler;
use Commerce\Ordering\Domain\Product\ProductStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\Doubles\Ordering\RecordedCatalogSync;
use Tests\TestCase;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;

final class CatalogSnapshotHandlerTest extends TestCase
{
    private const string PRODUCT = '01999a1f-0a1b-7c2d-8e3f-4a5b6c7d8e9f';

    private RecordedCatalogSync $catalog;

    private CatalogSnapshotHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new RecordedCatalogSync();
        $this->handler = new CatalogSnapshotHandler($this->catalog, new NullLogger());
    }

    #[Test]
    public function a_snapshot_updates_the_copy_with_what_checkout_needs(): void
    {
        $this->handler->handle(self::message(self::event()));

        [$snapshot] = $this->catalog->snapshots;
        self::assertSame(self::PRODUCT, $snapshot->productId);
        self::assertSame('BOOK-DDD-001', (string) $snapshot->sku);
        self::assertSame(18990, $snapshot->price->cents());
        self::assertSame(ProductStatus::Active, $snapshot->status);
        self::assertSame(3, $snapshot->version);
    }

    #[Test]
    public function events_of_another_type_are_not_ours(): void
    {
        $this->handler->handle(self::message(self::event(type: 'tucano.catalog.product.renamed')));

        self::assertSame([], $this->catalog->snapshots);
    }

    #[Test]
    public function a_tombstone_changes_nothing(): void
    {
        $this->handler->handle(self::message(''));

        self::assertSame([], $this->catalog->snapshots);
    }

    #[Test]
    #[DataProvider('unreadable')]
    public function an_unreadable_snapshot_goes_straight_to_the_dead_letters(string $payload): void
    {
        $this->expectException(PermanentFailure::class);

        $this->handler->handle(self::message($payload));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        yield 'not json' => ['{"specversion":'];
        yield 'no price' => [self::event(['price' => null])];
        yield 'a draft, which the catalog never publishes' => [self::event(['status' => 'draft'])];
        yield 'a version as text' => [self::event(['version' => '3'])];
        yield 'a sku outside the format' => [self::event(['sku' => 'BOOK_DDD_001'])];
    }

    private static function message(string $payload): ReceivedMessage
    {
        return new ReceivedMessage('catalog.products.v1', 1, 42, self::PRODUCT, $payload);
    }

    /** @param array<string, mixed> $data */
    private static function event(array $data = [], string $type = 'tucano.catalog.product.snapshot'): string
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
}
