<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * The catalog rules also live in MySQL, so a bug in the code (or a manual
 * UPDATE) cannot break them. MySQL reports most errors with a generic SQLSTATE
 * (HY000, 23000), so the assertions look at the driver error code instead.
 */
#[Group('integration')]
final class SchemaConstraintsTest extends TestCase
{
    private const int CHECK_VIOLATED = 3819;
    private const int DUPLICATE_ENTRY = 1062;
    private const int NO_REFERENCED_ROW = 1452;
    private const int DATA_TRUNCATED = 1265;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        // DDL commits on its own in MySQL, so the schema is built once, before the
        // first transaction, and every test rolls its changes back.
        if (!self::$migrated) {
            $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true]);
            self::$migrated = true;
        }
        $this->database()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->database()->rollBack();

        parent::tearDown();
    }

    #[Test]
    public function a_sku_outside_the_format_is_rejected(): void
    {
        $this->assertRejected(self::CHECK_VIOLATED, fn() => $this->insertProduct(['sku' => 'book-ddd-002']));
    }

    #[Test]
    public function a_negative_price_is_rejected(): void
    {
        $this->assertRejected(self::CHECK_VIOLATED, fn() => $this->insertProduct(['price_cents' => -1]));
    }

    #[Test]
    public function a_product_without_size_is_rejected(): void
    {
        $this->assertRejected(self::CHECK_VIOLATED, fn() => $this->insertProduct(['weight_grams' => 0]));
    }

    #[Test]
    public function a_sku_is_sold_once(): void
    {
        $this->assertRejected(self::DUPLICATE_ENTRY, fn() => $this->insertProduct(['sku' => 'BOOK-DDD-001']));
    }

    #[Test]
    public function a_product_needs_an_existing_category(): void
    {
        $this->assertRejected(self::NO_REFERENCED_ROW, fn() => $this->insertProduct(['category_id' => Uuid::uuid7()->getBytes()]));
    }

    #[Test]
    public function unknown_states_are_rejected(): void
    {
        $this->assertRejected(self::DATA_TRUNCATED, fn() => $this->insertProduct(['status' => 'sold_out']));
    }

    #[Test]
    public function the_readable_id_comes_from_the_binary_one(): void
    {
        $id = Uuid::uuid7();
        $this->insertProduct(['id' => $id->getBytes()]);

        self::assertSame($id->toString(), $this->database()->table('products')->where('id', $id->getBytes())->value('id_text'));
    }

    #[Test]
    public function seeding_again_keeps_what_changed_since(): void
    {
        $products = $this->database()->table('products');
        $products->clone()->where('sku', 'BOOK-DDD-001')->update(['price_cents' => 9990]);
        $count = $products->clone()->count();

        $this->artisan('db:seed', ['--force' => true]);

        self::assertSame(9990, (int) $products->clone()->where('sku', 'BOOK-DDD-001')->value('price_cents'));
        self::assertSame($count, $products->clone()->count());
    }

    private function database(): ConnectionInterface
    {
        return $this->app->make('db')->connection();
    }

    /** @param array<string, mixed> $overrides */
    private function insertProduct(array $overrides = []): void
    {
        $this->database()->table('products')->insert([
            'id' => Uuid::uuid7()->getBytes(),
            'sku' => 'BOOK-TEST-001',
            'name' => 'Test book',
            'category_id' => $this->database()->table('categories')->where('slug', 'books')->value('id'),
            'status' => 'active',
            'price_cents' => 1000,
            'currency' => 'BRL',
            'weight_grams' => 500,
            'length_mm' => 200,
            'width_mm' => 150,
            'height_mm' => 20,
            ...$overrides,
        ]);
    }

    private function assertRejected(int $mysqlError, callable $statement): void
    {
        try {
            $statement();
        } catch (QueryException $error) {
            self::assertSame($mysqlError, $error->errorInfo[1] ?? null, $error->getMessage());

            return;
        }

        self::fail(sprintf('Expected MySQL error %d, but the statement succeeded.', $mysqlError));
    }
}
