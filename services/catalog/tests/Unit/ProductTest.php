<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Dimensions;
use App\Models\NewProduct;
use App\Models\Product;
use App\Models\ProductChanges;
use App\Models\ProductStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\Products;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

final class ProductTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-28T09:30:00.250000Z');
    }

    #[Test]
    public function a_new_product_is_a_draft_at_version_one(): void
    {
        $input = new NewProduct('BOOK-REF-001', 'Refactoring', 'books', Money::of(15990, Currency::brl()), 900, new Dimensions(235, 180, 30));

        $product = Product::draft($input, $this->now);

        self::assertSame(ProductStatus::Draft, $product->status);
        self::assertSame(1, $product->version);
        self::assertSame('BOOK-REF-001', $product->sku);
        self::assertEquals($this->now, $product->updatedAt);
    }

    #[Test]
    public function a_revision_changes_only_what_was_sent(): void
    {
        $book = Products::book();

        $revised = $book->revised(new ProductChanges(price: Money::of(17990, Currency::brl())), $this->now);

        self::assertSame(17990, $revised->price->cents());
        self::assertSame($book->name, $revised->name);
        self::assertSame($book->category, $revised->category);
        self::assertSame($book->weightGrams, $revised->weightGrams);
        self::assertTrue($revised->dimensions->equals($book->dimensions));
        self::assertSame($book->status, $revised->status);
        self::assertSame(4, $revised->version);
        self::assertEquals($this->now, $revised->updatedAt);
    }

    #[Test]
    public function a_move_is_a_new_version(): void
    {
        $moved = Products::book()->movedTo(ProductStatus::Discontinued, $this->now);

        self::assertSame(ProductStatus::Discontinued, $moved->status);
        self::assertSame(4, $moved->version);
        self::assertEquals($this->now, $moved->updatedAt);
    }

    #[Test]
    public function the_state_ignores_the_version_and_the_time(): void
    {
        $book = Products::book();

        self::assertTrue($book->revised(new ProductChanges(name: 'Domain-Driven Design'), $this->now)->sameStateAs($book));
        self::assertTrue($book->revised(new ProductChanges(), $this->now)->sameStateAs($book));
        self::assertFalse($book->revised(new ProductChanges(weightGrams: 1200), $this->now)->sameStateAs($book));
        self::assertFalse($book->revised(new ProductChanges(dimensions: new Dimensions(240, 170, 45)), $this->now)->sameStateAs($book));
        self::assertFalse($book->movedTo(ProductStatus::Discontinued, $this->now)->sameStateAs($book));
    }

    #[Test]
    public function the_array_form_is_what_the_api_returns(): void
    {
        self::assertSame([
            'id' => Products::BOOK_ID,
            'sku' => 'BOOK-DDD-001',
            'name' => 'Domain-Driven Design',
            'status' => 'active',
            'category' => 'books',
            'price' => ['amount' => 18990, 'currency' => 'BRL'],
            'weightGrams' => 1100,
            'dimensions' => ['lengthMm' => 240, 'widthMm' => 170, 'heightMm' => 40],
            'version' => 3,
            'updatedAt' => '2026-09-27T12:00:04.567Z',
        ], Products::book()->toArray());
    }

    #[Test]
    public function the_array_form_survives_the_trip_through_the_cache(): void
    {
        $record = Products::book()->toArray();

        self::assertSame($record, Product::fromArray($record)->toArray());
    }

    #[Test]
    public function the_time_of_the_last_change_is_written_in_utc(): void
    {
        $revised = Products::book()->revised(new ProductChanges(name: 'DDD'), new DateTimeImmutable('2026-09-28T06:30:00.250-03:00'));

        self::assertSame('2026-09-28T09:30:00.250Z', $revised->toArray()['updatedAt']);
    }
}
