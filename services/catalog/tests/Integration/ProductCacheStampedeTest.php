<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Product;
use App\Services\ProductCache;
use App\Services\ProductCacheSettings;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Doubles\Products;
use Tests\IntegrationTestCase;

/**
 * Two requests miss the same key at the same time. The pause between polls is
 * where the other request gets to run, so the interleaving is deterministic.
 */
#[Group('integration')]
final class ProductCacheStampedeTest extends IntegrationTestCase
{
    private const string SKU = 'BOOK-DDD-001';

    private int $databaseReads = 0;

    #[Test]
    public function only_the_request_holding_the_lock_reads_mysql(): void
    {
        $holder = $this->productCache();
        $lock = $this->locks()->lock(ProductCache::lockKey(self::SKU), 5);
        self::assertTrue($lock->get());
        // While the waiter pauses, the holder finishes: it stores the entry and lets the lock go.
        $waiter = $this->productCache(function () use ($lock, $holder): void {
            if ($lock->release()) {
                $holder->remember(self::SKU, $this->readDatabase(...));
            }
        });

        $product = $waiter->remember(self::SKU, $this->readDatabase(...));

        self::assertSame(self::SKU, $product?->sku);
        self::assertSame(1, $this->databaseReads, 'Only the lock holder should read MySQL.');
    }

    #[Test]
    public function a_waiting_request_reads_mysql_itself_when_the_rebuild_never_comes(): void
    {
        $lock = $this->locks()->lock(ProductCache::lockKey(self::SKU), 5);
        self::assertTrue($lock->get());
        $waited = 0;
        $waiter = $this->productCache(static function (int $milliseconds) use (&$waited): void {
            $waited += $milliseconds;
        });

        $product = $waiter->remember(self::SKU, $this->readDatabase(...));

        self::assertSame(self::SKU, $product?->sku);
        self::assertSame(1, $this->databaseReads);
        self::assertSame(500, $waited, 'A waiting request gives up after half a second.');
        $lock->release();
    }

    #[Test]
    public function the_lock_is_free_again_after_a_rebuild(): void
    {
        $this->productCache()->remember(self::SKU, $this->readDatabase(...));

        $lock = $this->locks()->lock(ProductCache::lockKey(self::SKU), 5);
        self::assertTrue($lock->get());
        $lock->release();
    }

    #[Test]
    public function a_failed_rebuild_lets_the_lock_go(): void
    {
        try {
            $this->productCache()->remember(self::SKU, static fn(): never => throw new RuntimeException('MySQL is gone'));
            self::fail('The rebuild should have failed.');
        } catch (RuntimeException $error) {
            self::assertSame('MySQL is gone', $error->getMessage());
        }

        $lock = $this->locks()->lock(ProductCache::lockKey(self::SKU), 5);
        self::assertTrue($lock->get(), 'The next request must be able to rebuild the entry.');
        $lock->release();
    }

    /** @param (Closure(int): void)|null $pause */
    private function productCache(?Closure $pause = null): ProductCache
    {
        return new ProductCache($this->app->make(Repository::class), $this->locks(), new NullLogger(), $this->app->make(ProductCacheSettings::class), $pause);
    }

    private function locks(): LockProvider
    {
        return $this->app->make(LockProvider::class);
    }

    private function readDatabase(): Product
    {
        $this->databaseReads++;

        return Products::book();
    }
}
