<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\IntegrationTestCase;

/** The stores the seed opens and what each of them sells (docs/adr/0031-a-store-is-a-tenant.md). */
#[Group('integration')]
final class SeededStoresTest extends IntegrationTestCase
{
    #[Test]
    public function every_seeded_product_is_sold_by_the_store_of_its_category(): void
    {
        $pairs = $this->database()->table('products AS p')
            ->join('categories AS c', 'c.id', '=', 'p.category_id')
            ->join('stores AS s', 's.id', '=', 'p.store_id')
            ->distinct()
            ->orderBy('c.slug')
            ->get(['c.slug AS category', 's.slug AS store'])
            ->map(static fn(stdClass $row): string => "{$row->category} in {$row->store}")
            ->all();

        self::assertSame(['books in arara', 'electronics in bemtevi', 'home in sabia', 'sports in sabia'], $pairs);
    }

    #[Test]
    public function the_mug_the_chaos_probes_buy_is_in_sabia(): void
    {
        self::assertSame('sabia', $this->storeOf('HOME-MUG-001'));
    }

    #[Test]
    public function seeding_again_renames_a_store_and_never_moves_a_product(): void
    {
        $stores = $this->database()->table('stores');
        $stores->clone()->where('slug', 'arara')->update(['name' => 'Arara', 'palette' => 'sabia']);
        $this->database()->table('products')->where('sku', 'BOOK-DDD-001')->update(['store_id' => $this->storeId('sabia')]);
        $products = $this->database()->table('products')->count();

        $this->artisan('db:seed', ['--force' => true]);

        $arara = $stores->clone()->where('slug', 'arara')->first(['name', 'palette']);
        self::assertInstanceOf(stdClass::class, $arara);
        self::assertSame(['Arara Livros', 'arara'], [$arara->name, $arara->palette]);
        self::assertSame(3, $stores->clone()->count());
        self::assertSame('sabia', $this->storeOf('BOOK-DDD-001'));
        self::assertSame($products, $this->database()->table('products')->count());
    }
}
