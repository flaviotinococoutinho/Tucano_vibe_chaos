<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use Commerce\Ordering\Application\CatalogSnapshot;
use Commerce\Ordering\Application\Port\Driven\ForFindingProducts;
use Commerce\Ordering\Application\Port\Driven\ForStoringCatalogCopies;
use Commerce\Ordering\Domain\Product\ProductStatus;
use Commerce\Ordering\Domain\Product\Sku;
use Commerce\Ordering\Domain\Store\StoreSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

#[Group('integration')]
final class PostgresCatalogCopiesTest extends TestCase
{
    use RefreshDatabase;

    private const string PRODUCT = '01999a1f-0a1b-7c2d-8e3f-4a5b6c7d8e9f';

    private ForStoringCatalogCopies $copies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->copies = $this->app->make(ForStoringCatalogCopies::class);
    }

    #[Test]
    public function a_new_product_enters_the_copy_with_its_store(): void
    {
        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 1, cents: 18990)));

        self::assertSame(18990, $this->price());
        self::assertSame('arara', $this->store());
        $product = $this->app->make(ForFindingProducts::class)->bySku(Sku::of('BOOK-DDD-001'))['BOOK-DDD-001'];
        self::assertTrue($product->belongsTo(StoreSlug::of('arara')), 'checkout reads the store the copy keeps');
        self::assertFalse($product->belongsTo(StoreSlug::of('sabia')));
    }

    #[Test]
    public function the_same_version_again_changes_nothing(): void
    {
        $this->copies->saveIfNewer(self::snapshot(version: 1, cents: 18990));

        self::assertFalse($this->copies->saveIfNewer(self::snapshot(version: 1, cents: 18990)));
    }

    #[Test]
    public function an_older_version_arriving_late_changes_nothing(): void
    {
        $this->copies->saveIfNewer(self::snapshot(version: 3, cents: 15990));

        self::assertFalse($this->copies->saveIfNewer(self::snapshot(version: 2, cents: 18990)));
        self::assertSame(15990, $this->price());
    }

    #[Test]
    public function a_newer_version_replaces_the_copy(): void
    {
        $this->copies->saveIfNewer(self::snapshot(version: 1, cents: 18990));

        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 2, cents: 15990, status: ProductStatus::Discontinued)));
        self::assertSame(15990, $this->price());
        self::assertSame('discontinued', DB::table('product_snapshots')->where('product_id', self::PRODUCT)->value('status'));
    }

    #[Test]
    public function a_product_from_before_the_stores_is_copied_without_one(): void
    {
        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 1, cents: 18990, store: null)));

        self::assertNull($this->store());
        $product = $this->app->make(ForFindingProducts::class)->bySku(Sku::of('BOOK-DDD-001'))['BOOK-DDD-001'];
        self::assertFalse($product->belongsTo(StoreSlug::of('arara')), 'no store takes it until the catalog tells its store');
    }

    #[Test]
    public function the_same_version_tells_the_store_of_a_product_from_before_the_stores(): void
    {
        // The catalog gives its old products a store and sends them again, maybe with no new version.
        $this->copies->saveIfNewer(self::snapshot(version: 4, cents: 18990, store: null));

        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 4, cents: 18990)));
        self::assertSame('arara', $this->store());
        self::assertFalse($this->copies->saveIfNewer(self::snapshot(version: 4, cents: 18990)), 'once the store is known, the same version is the same again');
    }

    #[Test]
    public function a_snapshot_without_a_store_never_takes_away_the_one_the_copy_knows(): void
    {
        $this->copies->saveIfNewer(self::snapshot(version: 1, cents: 18990));

        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 2, cents: 15990, store: null)));
        self::assertSame(15990, $this->price(), 'the rest of the snapshot still moves the copy forward');
        self::assertSame('arara', $this->store());
        self::assertFalse($this->copies->saveIfNewer(self::snapshot(version: 2, cents: 15990, store: null)));
    }

    private static function snapshot(int $version, int $cents, ProductStatus $status = ProductStatus::Active, ?string $store = 'arara'): CatalogSnapshot
    {
        return new CatalogSnapshot(
            self::PRODUCT,
            Sku::of('BOOK-DDD-001'),
            'Domain-Driven Design',
            Money::of($cents, Currency::brl()),
            $status,
            $store === null ? null : StoreSlug::of($store),
            $version,
        );
    }

    private function price(): int
    {
        return (int) DB::table('product_snapshots')->where('product_id', self::PRODUCT)->value('price_cents');
    }

    private function store(): ?string
    {
        $store = DB::table('product_snapshots')->where('product_id', self::PRODUCT)->value('store');

        return $store === null ? null : (string) $store;
    }
}
