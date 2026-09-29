<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\IntegrationTestCase;

/**
 * The catalog rules also live in MySQL, so a bug in the code (or a manual
 * UPDATE) cannot break them. MySQL reports most errors with a generic SQLSTATE
 * (HY000, 23000), so the assertions look at the driver error code instead.
 */
#[Group('integration')]
final class SchemaConstraintsTest extends IntegrationTestCase
{
    private const int CHECK_VIOLATED = 3819;
    private const int DUPLICATE_ENTRY = 1062;
    private const int NO_REFERENCED_ROW = 1452;
    private const int DATA_TRUNCATED = 1265;
    private const int CANNOT_BE_NULL = 1048;
    private const int ROW_IS_REFERENCED = 1451;

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
    public function a_product_needs_a_store(): void
    {
        $this->assertRejected(self::CANNOT_BE_NULL, fn() => $this->insertProduct(['store_id' => null]));
    }

    #[Test]
    public function a_product_needs_an_existing_store(): void
    {
        $this->assertRejected(self::NO_REFERENCED_ROW, fn() => $this->insertProduct(['store_id' => Uuid::uuid7()->getBytes()]));
    }

    #[Test]
    public function a_store_with_products_stays(): void
    {
        $this->assertRejected(self::ROW_IS_REFERENCED, fn() => $this->database()->table('stores')->where('slug', 'arara')->delete());
    }

    #[Test]
    public function a_store_slug_outside_the_format_is_rejected(): void
    {
        $this->assertRejected(self::CHECK_VIOLATED, fn() => $this->insertStore(['slug' => 'Urutau']));
        $this->assertRejected(self::CHECK_VIOLATED, fn() => $this->insertStore(['slug' => 'u']));
        $this->assertRejected(self::CHECK_VIOLATED, fn() => $this->insertStore(['slug' => '9urutau']));
    }

    #[Test]
    public function a_store_slug_is_taken_once(): void
    {
        $this->assertRejected(self::DUPLICATE_ENTRY, fn() => $this->insertStore(['slug' => 'arara']));
    }

    #[Test]
    public function a_palette_outside_the_design_system_is_rejected(): void
    {
        $this->assertRejected(self::DATA_TRUNCATED, fn() => $this->insertStore(['palette' => 'pink']));
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
