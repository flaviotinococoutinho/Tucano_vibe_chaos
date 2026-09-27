<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProductCache;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Doubles\Blackhole;
use Tests\IntegrationTestCase;

#[Group('integration')]
final class ProductCachingTest extends IntegrationTestCase
{
    #[Test]
    public function the_second_read_comes_from_the_cache(): void
    {
        $logs = $this->captureLogs();

        $this->json('GET', '/v1/products/BOOK-DDD-001');
        // Behind the service's back: only a cache hit still shows the old price.
        $this->database()->table('products')->where('sku', 'BOOK-DDD-001')->update(['price_cents' => 1]);
        $this->json('GET', '/v1/products/BOOK-DDD-001');

        $this->response->assertOk()->assertJsonPath('price.amount', 18990);
        self::assertTrue($logs->hasDebug(['message' => 'product cache miss', 'context' => ['sku' => 'BOOK-DDD-001']]));
        self::assertTrue($logs->hasDebug(['message' => 'product cache hit', 'context' => ['sku' => 'BOOK-DDD-001']]));
    }

    #[Test]
    public function a_product_stays_cached_for_five_minutes_give_or_take_ten_percent(): void
    {
        $this->json('GET', '/v1/products/BOOK-DDD-001');

        $ttl = $this->cacheTtl(ProductCache::key('BOOK-DDD-001'));
        // A few seconds of slack for a slow runner between the write and this read.
        self::assertGreaterThanOrEqual(265, $ttl);
        self::assertLessThanOrEqual(330, $ttl);
    }

    #[Test]
    public function a_change_drops_the_cached_product(): void
    {
        $this->json('GET', '/v1/products/BOOK-DDD-001');
        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['price' => ['amount' => 17990, 'currency' => 'BRL']]);

        $this->json('GET', '/v1/products/BOOK-DDD-001');

        $this->response->assertJsonPath('price.amount', 17990)->assertHeader('ETag', '"2"');
    }

    #[Test]
    public function a_move_drops_it_too(): void
    {
        $this->json('GET', '/v1/products/BOOK-DDD-001');
        $this->json('POST', '/v1/products/BOOK-DDD-001/discontinue');

        $this->json('GET', '/v1/products/BOOK-DDD-001');

        $this->response->assertJsonPath('status', 'discontinued');
    }

    #[Test]
    public function an_unknown_sku_is_remembered_for_a_short_while(): void
    {
        $this->json('GET', '/v1/products/BOOK-TEST-001');
        // An active product that shows up behind the service's back stays hidden until the entry expires.
        $this->insertProduct(['sku' => 'BOOK-TEST-001']);

        $this->json('GET', '/v1/products/BOOK-TEST-001');

        $this->response->assertNotFound();
        $ttl = $this->cacheTtl(ProductCache::key('BOOK-TEST-001'));
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(30, $ttl);
    }

    #[Test]
    public function a_draft_shows_up_as_soon_as_it_goes_on_sale(): void
    {
        $this->json('GET', '/v1/products/HOME-LAMP-001');
        $this->response->assertNotFound();

        $this->json('POST', '/v1/products/HOME-LAMP-001/activate');
        $this->json('GET', '/v1/products/HOME-LAMP-001');

        $this->response->assertOk()->assertJsonPath('status', 'active');
    }

    #[Test]
    public function creating_a_product_drops_a_cached_not_found(): void
    {
        $this->json('GET', '/v1/products/BOOK-REF-001');
        $cache = $this->app->make('cache.store');
        self::assertTrue($cache->has(ProductCache::key('BOOK-REF-001')));

        $this->json('POST', '/v1/products', [
            'sku' => 'BOOK-REF-001',
            'name' => 'Refactoring',
            'category' => 'books',
            'price' => ['amount' => 15990, 'currency' => 'BRL'],
            'weightGrams' => 900,
            'dimensions' => ['lengthMm' => 235, 'widthMm' => 180, 'heightMm' => 30],
        ]);

        $this->response->assertCreated();
        self::assertFalse($cache->has(ProductCache::key('BOOK-REF-001')));
    }

    #[Test]
    public function reads_go_to_mysql_when_redis_stops_answering(): void
    {
        $logs = $this->captureLogs();
        $blackhole = Blackhole::open();
        $this->pointRedisAt($blackhole);

        $this->json('GET', '/v1/products/BOOK-DDD-001');

        $this->response->assertOk()->assertJsonPath('sku', 'BOOK-DDD-001');
        self::assertTrue($logs->hasWarningThatContains('product cache unavailable'));
    }

    #[Test]
    public function writes_succeed_when_redis_stops_answering(): void
    {
        $logs = $this->captureLogs();
        $blackhole = Blackhole::open();
        $this->pointRedisAt($blackhole);

        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['price' => ['amount' => 17990, 'currency' => 'BRL']]);

        $this->response->assertOk()->assertJsonPath('version', 2);
        self::assertCount(1, $this->kafka->delivered());
        self::assertTrue($logs->hasWarningThatContains('product cache not invalidated'));
    }

    /** Both connections the cache uses: entries live on "cache" and locks on "default". */
    private function pointRedisAt(Blackhole $blackhole): void
    {
        foreach (['default', 'cache'] as $connection) {
            config([
                "database.redis.{$connection}.host" => '127.0.0.1',
                "database.redis.{$connection}.port" => $blackhole->port(),
            ]);
        }
        $this->app->forgetInstance('redis');
    }
}
