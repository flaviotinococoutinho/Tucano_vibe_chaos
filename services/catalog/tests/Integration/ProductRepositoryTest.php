<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\DuplicateSku;
use App\Models\Dimensions;
use App\Models\NewProduct;
use App\Models\Product;
use App\Models\ProductChanges;
use App\Models\ProductStatus;
use App\Repositories\ProductRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

#[Group('integration')]
final class ProductRepositoryTest extends IntegrationTestCase
{
    #[Test]
    public function a_product_comes_back_as_it_was_stored(): void
    {
        $product = $this->refactoring();

        $this->repository()->add($product);

        self::assertEquals($product, $this->repository()->findBySku('BOOK-REF-001'));
    }

    #[Test]
    public function a_sku_is_stored_once(): void
    {
        $this->expectExceptionObject(DuplicateSku::of('BOOK-DDD-001'));

        $this->repository()->add(Product::draft(
            new NewProduct('BOOK-DDD-001', 'Copy', 'arara', 'books', Money::of(100, Currency::brl()), 100, Dimensions::ofMillimetres(1, 1, 1)),
            new DateTimeImmutable(),
        ));
    }

    #[Test]
    public function a_page_of_a_store_holds_only_what_the_store_sells(): void
    {
        $page = $this->repository()->activePage('sabia', null, 1);

        self::assertSame(6, $page->total);
        self::assertSame(['sabia'], array_values(array_unique(array_map(static fn(Product $product): string => $product->store, $page->products))));
    }

    #[Test]
    public function a_page_of_the_platform_holds_every_store(): void
    {
        $stores = array_map(static fn(Product $product): string => $product->store, $this->repository()->activePage(null, null, 1)->products);

        self::assertSame(['arara', 'bemtevi', 'sabia'], array_values(array_unique(self::sorted($stores))));
    }

    #[Test]
    public function a_write_based_on_a_stale_read_changes_nothing(): void
    {
        $read = $this->repository()->findBySku('BOOK-DDD-001');
        self::assertNotNull($read);
        $now = new DateTimeImmutable();
        // Two requests read version 1 and both try to write version 2: the second one loses.
        $first = $read->revised(new ProductChanges(name: 'DDD'), $now);
        $second = $read->revised(new ProductChanges(name: 'Blue book'), $now);

        self::assertTrue($this->repository()->update($first, $read->version));
        self::assertFalse($this->repository()->update($second, $read->version));

        $stored = $this->repository()->findBySku('BOOK-DDD-001');
        self::assertSame('DDD', $stored?->name);
        self::assertSame(2, $stored->version);
    }

    #[Test]
    public function published_products_come_in_batches_in_id_order(): void
    {
        $batches = iterator_to_array($this->repository()->published(null, 5), false);

        self::assertSame([5, 5, 5, 2], array_map(count(...), $batches));
        $products = array_merge(...$batches);
        $ids = array_map(static fn(Product $product): string => $product->id->toString(), $products);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids);
        self::assertNotContains(ProductStatus::Draft, array_map(static fn(Product $product): ProductStatus => $product->status, $products));
    }

    #[Test]
    public function published_products_can_be_narrowed_to_one_sku(): void
    {
        $batches = iterator_to_array($this->repository()->published('BOOK-DDD-001', 5), false);

        self::assertCount(1, $batches);
        self::assertSame('BOOK-DDD-001', $batches[0][0]->sku);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function repository(): ProductRepository
    {
        return $this->app->make(ProductRepository::class);
    }

    private function refactoring(): Product
    {
        return Product::draft(
            new NewProduct('BOOK-REF-001', 'Refactoring', 'arara', 'books', Money::of(15990, Currency::brl()), 900, Dimensions::ofMillimetres(235, 180, 30)),
            new DateTimeImmutable('2026-09-28T10:15:30.123456Z'),
        );
    }
}
