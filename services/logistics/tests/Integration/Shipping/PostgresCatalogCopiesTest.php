<?php

declare(strict_types=1);

namespace Tests\Integration\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Logistics\Shipping\Application\CatalogSnapshot;
use Logistics\Shipping\Application\Port\Driven\ForFindingProducts;
use Logistics\Shipping\Application\Port\Driven\ForStoringCatalogCopies;
use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Product\Sku;
use Logistics\Shipping\Domain\Shipment\StoreSlug;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\CatalogEvents;
use Tests\TestCase;

#[Group('integration')]
final class PostgresCatalogCopiesTest extends TestCase
{
    use RefreshDatabase;

    private ForStoringCatalogCopies $copies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->copies = $this->app->make(ForStoringCatalogCopies::class);
    }

    #[Test]
    public function a_new_product_enters_the_copy_and_can_be_packed(): void
    {
        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 1, grams: 1100)));

        $products = $this->app->make(ForFindingProducts::class)->bySku(Sku::of('BOOK-DDD-001'), Sku::of('HOME-MUG-001'));

        self::assertSame(['BOOK-DDD-001'], array_keys($products));
        self::assertSame(1100, $products['BOOK-DDD-001']->weight->grams());
        self::assertEquals(Dimensions::ofMillimetres(240, 170, 40), $products['BOOK-DDD-001']->dimensions);
        self::assertEquals(StoreSlug::of('arara'), $products['BOOK-DDD-001']->store);
    }

    #[Test]
    public function the_same_version_again_changes_nothing(): void
    {
        $this->copies->saveIfNewer(self::snapshot(version: 1, grams: 1100));

        self::assertFalse($this->copies->saveIfNewer(self::snapshot(version: 1, grams: 1100)));
    }

    #[Test]
    public function an_older_version_arriving_late_changes_nothing(): void
    {
        $this->copies->saveIfNewer(self::snapshot(version: 3, grams: 1250));

        self::assertFalse($this->copies->saveIfNewer(self::snapshot(version: 2, grams: 1100)));
        self::assertSame(1250, $this->weight());
    }

    #[Test]
    public function a_newer_version_replaces_the_copy(): void
    {
        $this->copies->saveIfNewer(self::snapshot(version: 1, grams: 1100));

        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 2, grams: 1250)));
        self::assertSame(1250, $this->weight());
    }

    #[Test]
    public function a_product_from_before_the_stores_waits_in_the_copy_without_one(): void
    {
        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 3, grams: 1100, store: null)));

        self::assertNull($this->store());
        self::assertNull($this->app->make(ForFindingProducts::class)->bySku(Sku::of('BOOK-DDD-001'))['BOOK-DDD-001']->store);
    }

    #[Test]
    public function the_catalog_republishing_the_same_version_with_the_store_fills_it_in(): void
    {
        // The catalog republishes every product once the stores exist, and nothing else changed to bump the version.
        $this->copies->saveIfNewer(self::snapshot(version: 3, grams: 1100, store: null));

        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 3, grams: 1100)));
        self::assertSame('arara', $this->store());
        self::assertFalse($this->copies->saveIfNewer(self::snapshot(version: 3, grams: 1100)), 'Once the copy has the store, the same version is old news again.');
    }

    #[Test]
    public function a_snapshot_without_the_store_never_erases_the_one_the_copy_knows(): void
    {
        $this->copies->saveIfNewer(self::snapshot(version: 3, grams: 1100));

        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 4, grams: 1250, store: null)));
        self::assertSame([1250, 'arara'], [$this->weight(), $this->store()]);
        self::assertFalse($this->copies->saveIfNewer(self::snapshot(version: 4, grams: 1250, store: null)));
    }

    private static function snapshot(int $version, int $grams, ?string $store = CatalogEvents::STORE): CatalogSnapshot
    {
        return new CatalogSnapshot(
            CatalogEvents::PRODUCT,
            Sku::of('BOOK-DDD-001'),
            $store === null ? null : StoreSlug::of($store),
            'Domain-Driven Design',
            Weight::ofGrams($grams),
            Dimensions::ofMillimetres(240, 170, 40),
            $version,
        );
    }

    private function weight(): int
    {
        return (int) DB::table('product_snapshots')->where('product_id', CatalogEvents::PRODUCT)->value('weight_grams');
    }

    private function store(): ?string
    {
        $store = DB::table('product_snapshots')->where('product_id', CatalogEvents::PRODUCT)->value('store');

        return $store === null ? null : (string) $store;
    }
}
