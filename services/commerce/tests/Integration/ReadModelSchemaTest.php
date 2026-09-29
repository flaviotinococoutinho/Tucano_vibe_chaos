<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Int64;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Model\IndexInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/** The read model collection is created with a JSON Schema validator and its indexes. */
#[Group('integration')]
final class ReadModelSchemaTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = $this->app->make(Database::class);
        $this->database->drop();
        self::assertSame(0, Artisan::call('mongo:migrate'));
    }

    #[Test]
    public function documents_outside_the_schema_are_rejected(): void
    {
        $this->expectException(BulkWriteException::class);

        $this->database->selectCollection('order_views')->insertOne(['status' => 'paid']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function storesInAnotherShape(): iterable
    {
        yield 'a name instead of the slug' => ['Sabiá Casa e Esporte'];
        yield 'capitals' => ['Sabia'];
        yield 'one letter' => ['s'];
        yield 'a number' => [7];
    }

    #[Test]
    #[DataProvider('storesInAnotherShape')]
    public function a_view_with_a_store_in_another_shape_is_rejected(mixed $store): void
    {
        $this->expectException(BulkWriteException::class);

        $this->database->selectCollection('order_views')->insertOne(self::orderView(['store' => $store]));
    }

    #[Test]
    public function a_view_takes_the_slug_of_its_store_or_none_from_before_the_stores(): void
    {
        $views = $this->database->selectCollection('order_views');

        $views->insertOne(self::orderView(['store' => 'sabia']));
        $views->insertOne(self::orderView(['store' => null]));
        $views->insertOne(self::orderView());

        self::assertSame(3, $views->countDocuments());
    }

    #[Test]
    public function the_list_of_a_customer_in_a_store_has_its_index(): void
    {
        $indexes = [];
        foreach ($this->database->selectCollection('order_views')->listIndexes() as $index) {
            self::assertInstanceOf(IndexInfo::class, $index);
            $indexes[$index->getName()] = $index->getKey();
        }

        self::assertSame(['store' => 1, 'customerId' => 1, 'placedAt' => -1], $indexes['store_customer_history'] ?? null);
        self::assertArrayNotHasKey('customer_history', $indexes, 'no read goes by the customer alone anymore');
        self::assertArrayHasKey('order_number', $indexes);
    }

    #[Test]
    public function running_the_migrations_again_changes_nothing(): void
    {
        self::assertSame(0, Artisan::call('mongo:migrate'));
        self::assertStringContainsString('up to date', Artisan::output());
    }

    /**
     * A view as the projection writes it, with the changes of the test on top.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function orderView(array $changes = []): array
    {
        $now = new UTCDateTime(new DateTimeImmutable('2026-09-29T12:00:00Z'));

        return [
            '_id' => new Binary(Uuid::uuid7()->getBytes(), Binary::TYPE_UUID),
            'orderNumber' => new Int64(random_int(1, PHP_INT_MAX)),
            'customerId' => new Binary(Uuid::uuid7()->getBytes(), Binary::TYPE_UUID),
            'status' => 'pending_payment',
            'cancellationReason' => null,
            'total' => ['amount' => new Int64(4990), 'currency' => 'BRL'],
            'lines' => [['sku' => 'HOME-MUG-001', 'name' => 'Caneca de cerâmica', 'quantity' => 1, 'unitPrice' => new Int64(4990)]],
            'shipment' => null,
            'placedAt' => $now,
            'updatedAt' => $now,
            'version' => new Int64(1),
            ...$changes,
        ];
    }
}
