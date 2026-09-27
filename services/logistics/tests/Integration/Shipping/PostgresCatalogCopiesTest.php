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

    private static function snapshot(int $version, int $grams): CatalogSnapshot
    {
        return new CatalogSnapshot(
            CatalogEvents::PRODUCT,
            Sku::of('BOOK-DDD-001'),
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
}
