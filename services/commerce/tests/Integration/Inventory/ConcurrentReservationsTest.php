<?php

declare(strict_types=1);

namespace Tests\Integration\Inventory;

use Commerce\Inventory\Application\Port\Driven\ForHoldingStock;
use Commerce\Inventory\Application\ReservationStrategy;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Database\Seeders\FulfillmentCenterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\FeatureFlags\InMemoryFlags;

/**
 * Real races: eight processes, one PostgreSQL connection each, released at the
 * same moment to take one unit each out of five. The other processes only see
 * committed data, so this class does not use RefreshDatabase and removes the
 * stock it creates. The naive strategy is left out: its lost update is a
 * matter of timing, which is what the overselling lab shows.
 */
#[Group('integration')]
final class ConcurrentReservationsTest extends TestCase
{
    private const int UNITS = 5;

    private const int BUYERS = 8;

    private const string SKU = 'LAB-RACE-001';

    protected function setUp(): void
    {
        parent::setUp();
        if (!RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->seed(FulfillmentCenterSeeder::class);
        DB::table('stock_items')->where('sku', self::SKU)->delete();
        DB::table('stock_items')->insert(['sku' => self::SKU, 'fulfillment_center' => 'GRU1', 'on_hand' => self::UNITS, 'reserved' => 0]);
    }

    protected function tearDown(): void
    {
        DB::table('stock_items')->where('sku', self::SKU)->delete();

        parent::tearDown();
    }

    /** @return iterable<string, array{ReservationStrategy, int|null}> */
    public static function strategies(): iterable
    {
        // Locks make every buyer wait its turn, so all five units always sell.
        yield 'atomic' => [ReservationStrategy::Atomic, self::UNITS];
        yield 'pessimistic' => [ReservationStrategy::Pessimistic, self::UNITS];
        // These give up after a few lost rounds, so a unit may stay unsold, but never sold twice.
        yield 'optimistic' => [ReservationStrategy::Optimistic, null];
        yield 'serializable' => [ReservationStrategy::Serializable, null];
    }

    #[Test]
    #[DataProvider('strategies')]
    public function eight_buyers_never_hold_more_than_the_five_units(ReservationStrategy $strategy, ?int $exactlyHeld): void
    {
        $outcomes = $this->race($strategy);

        $held = count(array_filter($outcomes, static fn(string $outcome): bool => $outcome === 'held'));
        $reserved = (int) DB::table('stock_items')->where('sku', self::SKU)->value('reserved');
        self::assertSame($held, $reserved, 'Every successful hold is counted once: ' . implode(' | ', $outcomes));
        self::assertLessThanOrEqual(self::UNITS, $held);
        if ($exactlyHeld !== null) {
            self::assertSame($exactlyHeld, $held, implode(' | ', $outcomes));
        }
    }

    /** @return list<string> one outcome per buyer: held, refused, or failed with the reason */
    private function race(ReservationStrategy $strategy): array
    {
        $this->app->instance(FeatureFlags::class, new InMemoryFlags(['inventory.reservation-strategy' => $strategy->value]));
        $starter = sys_get_temp_dir() . '/tucano-race-' . bin2hex(random_bytes(6));
        mkdir($starter);
        // A child must never reuse the socket of the parent: each process opens its own connection.
        DB::disconnect();

        $children = [];
        for ($buyer = 0; $buyer < self::BUYERS; $buyer++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Could not fork a buyer.');
            }
            if ($pid === 0) {
                $this->buy($strategy, $starter, $buyer);
            }
            $children[] = $pid;
        }
        touch($starter . '/go');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $outcomes = [];
        for ($buyer = 0; $buyer < self::BUYERS; $buyer++) {
            $outcomes[] = (string) file_get_contents(sprintf('%s/%d', $starter, $buyer));
            unlink(sprintf('%s/%d', $starter, $buyer));
        }
        unlink($starter . '/go');
        rmdir($starter);

        return $outcomes;
    }

    private function buy(ReservationStrategy $strategy, string $starter, int $buyer): never
    {
        try {
            DB::purge();
            while (!file_exists($starter . '/go')) {
                usleep(200);
            }
            $held = $this->app->make(ForRunningTransactions::class)->run(
                fn(): bool => $this->app->make(ForHoldingStock::class)->hold('GRU1', self::SKU, 1),
                $strategy->isolation(),
            );
            $outcome = $held ? 'held' : 'refused';
        } catch (Throwable $failure) {
            $outcome = 'failed: ' . $failure->getMessage();
        }
        file_put_contents(sprintf('%s/%d', $starter, $buyer), $outcome);

        // The parent owns the shutdown of PHPUnit and Laravel; the child leaves without running it.
        posix_kill(posix_getpid(), SIGKILL);

        exit(1);
    }
}
