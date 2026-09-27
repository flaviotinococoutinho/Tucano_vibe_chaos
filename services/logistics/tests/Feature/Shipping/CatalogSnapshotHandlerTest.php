<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Logistics\Shipping\Adapter\Driving\Kafka\CatalogSnapshotHandler;
use Logistics\Shipping\Domain\Parcel\Dimensions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\Doubles\Shipping\RecordedCatalogSync;
use Tests\Fixtures\CatalogEvents;
use Tests\TestCase;
use Tucano\Messaging\Kafka\PermanentFailure;

final class CatalogSnapshotHandlerTest extends TestCase
{
    private RecordedCatalogSync $catalog;

    private CatalogSnapshotHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new RecordedCatalogSync();
        $this->handler = new CatalogSnapshotHandler($this->catalog, new NullLogger());
    }

    #[Test]
    public function a_snapshot_updates_the_copy_with_the_weight_and_the_size(): void
    {
        $this->handler->handle(CatalogEvents::message(CatalogEvents::snapshot()));

        self::assertCount(1, $this->catalog->snapshots);
        $snapshot = $this->catalog->snapshots[0];
        self::assertSame([CatalogEvents::PRODUCT, 'BOOK-DDD-001', 'Domain-Driven Design', 1100, 3], [
            $snapshot->productId, (string) $snapshot->sku, $snapshot->name, $snapshot->weight->grams(), $snapshot->version,
        ]);
        self::assertEquals(Dimensions::ofMillimetres(240, 170, 40), $snapshot->dimensions);
    }

    #[Test]
    public function a_discontinued_product_keeps_its_size(): void
    {
        $this->handler->handle(CatalogEvents::message(CatalogEvents::snapshot(['status' => 'discontinued', 'version' => 4])));

        self::assertSame(4, $this->catalog->snapshots[0]->version);
    }

    #[Test]
    public function events_of_another_type_are_not_ours(): void
    {
        $this->handler->handle(CatalogEvents::message(CatalogEvents::snapshot(type: 'tucano.catalog.product.renamed')));

        self::assertSame([], $this->catalog->snapshots);
    }

    #[Test]
    public function a_tombstone_changes_nothing(): void
    {
        $this->handler->handle(CatalogEvents::message(''));

        self::assertSame([], $this->catalog->snapshots);
    }

    #[Test]
    #[DataProvider('unreadable')]
    public function an_unreadable_snapshot_goes_straight_to_the_dead_letters(string $payload): void
    {
        $this->expectException(PermanentFailure::class);

        $this->handler->handle(CatalogEvents::message($payload));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        yield 'not json' => ['{"specversion":'];
        yield 'no weight' => [CatalogEvents::snapshot(['weightGrams' => null])];
        yield 'a weight of zero' => [CatalogEvents::snapshot(['weightGrams' => 0])];
        yield 'a weight the column cannot hold' => [CatalogEvents::snapshot(['weightGrams' => 4_294_967_295])];
        yield 'no dimensions' => [CatalogEvents::snapshot(['dimensions' => null])];
        yield 'a height as text' => [CatalogEvents::snapshot(['dimensions' => ['lengthMm' => 240, 'widthMm' => 170, 'heightMm' => '40']])];
        yield 'a product id that is not a UUID' => [CatalogEvents::snapshot(['productId' => 'book-ddd'])];
        yield 'a version as text' => [CatalogEvents::snapshot(['version' => '3'])];
        yield 'a sku outside the format' => [CatalogEvents::snapshot(['sku' => 'BOOK_DDD_001'])];
    }
}
