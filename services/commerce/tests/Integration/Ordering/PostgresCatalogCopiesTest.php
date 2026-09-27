<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use Commerce\Ordering\Application\CatalogSnapshot;
use Commerce\Ordering\Application\Port\Driven\ForStoringCatalogCopies;
use Commerce\Ordering\Domain\Product\ProductStatus;
use Commerce\Ordering\Domain\Product\Sku;
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
    public function a_new_product_enters_the_copy(): void
    {
        self::assertTrue($this->copies->saveIfNewer(self::snapshot(version: 1, cents: 18990)));

        self::assertSame(18990, $this->price());
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

    private static function snapshot(int $version, int $cents, ProductStatus $status = ProductStatus::Active): CatalogSnapshot
    {
        return new CatalogSnapshot(self::PRODUCT, Sku::of('BOOK-DDD-001'), 'Domain-Driven Design', Money::of($cents, Currency::brl()), $status, $version);
    }

    private function price(): int
    {
        return (int) DB::table('product_snapshots')->where('product_id', self::PRODUCT)->value('price_cents');
    }
}
