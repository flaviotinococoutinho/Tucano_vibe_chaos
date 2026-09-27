<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use Commerce\Inventory\Adapter\Driven\AtomicStockHolder;
use Commerce\Inventory\Adapter\Driven\OptimisticStockHolder;
use Commerce\Inventory\Adapter\Driven\PessimisticStockHolder;
use Commerce\Inventory\Adapter\Driven\ReadThenWriteStockHolder;
use Commerce\Inventory\Application\Port\Driven\ForHoldingStock;
use Database\Seeders\FulfillmentCenterSeeder;
use Database\Seeders\StockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** One buyer at a time: every strategy must agree. The races are in ConcurrentReservationsTest. */
#[Group('integration')]
final class StockHoldersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FulfillmentCenterSeeder::class, StockSeeder::class]);
    }

    /** @return iterable<string, array{class-string<ForHoldingStock>}> */
    public static function holders(): iterable
    {
        yield 'atomic' => [AtomicStockHolder::class];
        yield 'pessimistic' => [PessimisticStockHolder::class];
        yield 'optimistic' => [OptimisticStockHolder::class];
        yield 'read then write' => [ReadThenWriteStockHolder::class];
    }

    /** @param class-string<ForHoldingStock> $holderClass */
    #[Test]
    #[DataProvider('holders')]
    public function every_strategy_holds_what_is_free_and_refuses_the_rest(string $holderClass): void
    {
        $holder = $this->app->make($holderClass);

        // LAB-CONSOLE-001 has 5 units, all in GRU1.
        self::assertTrue($holder->hold('GRU1', 'LAB-CONSOLE-001', 3));
        self::assertFalse($holder->hold('GRU1', 'LAB-CONSOLE-001', 3));
        self::assertTrue($holder->hold('GRU1', 'LAB-CONSOLE-001', 2));
        self::assertFalse($holder->hold('BHZ1', 'LAB-CONSOLE-001', 1));
        self::assertSame(5, (int) DB::table('stock_items')->where(['sku' => 'LAB-CONSOLE-001', 'fulfillment_center' => 'GRU1'])->value('reserved'));
    }

    #[Test]
    public function the_optimistic_strategy_moves_the_version_on_every_hold(): void
    {
        $holder = $this->app->make(OptimisticStockHolder::class);

        $holder->hold('GRU1', 'LAB-CONSOLE-001', 1);
        $holder->hold('GRU1', 'LAB-CONSOLE-001', 1);

        self::assertSame(2, (int) DB::table('stock_items')->where(['sku' => 'LAB-CONSOLE-001', 'fulfillment_center' => 'GRU1'])->value('version'));
    }
}
