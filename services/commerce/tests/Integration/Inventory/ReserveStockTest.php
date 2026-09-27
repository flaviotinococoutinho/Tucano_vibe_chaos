<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use Commerce\Inventory\Application\Port\Driving\ForReservingStock;
use Commerce\Inventory\Application\ReservedStock;
use Commerce\Inventory\Application\StockItem;
use Commerce\Inventory\Application\StockRequest;
use Commerce\Inventory\Domain\InsufficientStock;
use Database\Seeders\FulfillmentCenterSeeder;
use Database\Seeders\StockSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/** ELEC-MON-027 and LAB-CONSOLE-001 are stocked in GRU1 only; the other SKUs are in both centers. */
#[Group('integration')]
final class ReserveStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FulfillmentCenterSeeder::class, StockSeeder::class]);
    }

    #[Test]
    public function the_center_in_the_destination_state_comes_first(): void
    {
        $reserved = $this->reserve('MG', ['BOOK-DDD-001' => 2]);

        self::assertSame('BHZ1', $reserved->fulfillmentCenter);
        self::assertSame(2, $this->reserved('BOOK-DDD-001', 'BHZ1'));
        self::assertSame(0, $this->reserved('BOOK-DDD-001', 'GRU1'));
    }

    #[Test]
    public function another_center_serves_what_the_first_one_lacks(): void
    {
        self::assertSame('GRU1', $this->reserve('MG', ['ELEC-MON-027' => 1])->fulfillmentCenter);
    }

    #[Test]
    public function a_center_that_falls_short_keeps_nothing_on_hold(): void
    {
        // BHZ1 holds the book, then misses the monitor: the savepoint gives the book back.
        $reserved = $this->reserve('MG', ['BOOK-DDD-001' => 1, 'ELEC-MON-027' => 1]);

        self::assertSame('GRU1', $reserved->fulfillmentCenter);
        self::assertSame(0, $this->reserved('BOOK-DDD-001', 'BHZ1'));
        self::assertSame(1, $this->reserved('BOOK-DDD-001', 'GRU1'));
        self::assertSame(1, $this->reserved('ELEC-MON-027', 'GRU1'));
    }

    #[Test]
    public function when_no_center_has_everything_nothing_changes(): void
    {
        try {
            $this->reserve('SP', ['BOOK-DDD-001' => 1, 'LAB-CONSOLE-001' => 6]);
            self::fail('The reservation should have been refused.');
        } catch (InsufficientStock $shortage) {
            self::assertSame('Not enough stock: GRU1 is short of LAB-CONSOLE-001; BHZ1 is short of LAB-CONSOLE-001.', $shortage->getMessage());
        }

        self::assertSame(0, (int) DB::table('stock_items')->sum('reserved'));
        self::assertSame(0, DB::table('stock_reservations')->count());
    }

    #[Test]
    public function each_hold_is_recorded_until_it_expires(): void
    {
        $this->reserve('SP', ['BOOK-DDD-001' => 2, 'HOME-MUG-001' => 3]);

        $rows = DB::table('stock_reservations')->orderBy('sku')->get(['sku', 'fulfillment_center', 'quantity', 'status', 'expires_at']);

        self::assertSame([['BOOK-DDD-001', 'GRU1', 2, 'active'], ['HOME-MUG-001', 'GRU1', 3, 'active']], $rows->map(
            static fn(stdClass $row): array => [$row->sku, $row->fulfillment_center, (int) $row->quantity, $row->status],
        )->all());
        self::assertEquals(new DateTimeImmutable('2026-09-27T12:15:00Z'), new DateTimeImmutable((string) $rows->first()?->expires_at));
    }

    #[Test]
    public function the_order_must_exist_when_the_transaction_commits(): void
    {
        // Nobody stores the order here. The deferred foreign key lets the reservation in and refuses it at commit time.
        $this->reserve('SP', ['BOOK-DDD-001' => 1]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('stock_reservations_order_id_fkey');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /** @param non-empty-array<string, int> $items SKU => quantity */
    private function reserve(string $state, array $items): ReservedStock
    {
        $stock = [];
        foreach ($items as $sku => $quantity) {
            $stock[] = new StockItem($sku, $quantity);
        }

        return $this->app->make(ForReservingStock::class)->reserve(
            new StockRequest(Uuid::uuid7()->toString(), $stock, $state, new DateTimeImmutable('2026-09-27T12:15:00Z')),
        );
    }

    private function reserved(string $sku, string $center): int
    {
        return (int) DB::table('stock_items')->where(['sku' => $sku, 'fulfillment_center' => $center])->value('reserved');
    }
}
