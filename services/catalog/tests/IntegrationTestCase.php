<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Redis\RedisManager;
use Ramsey\Uuid\Uuid;
use stdClass;

/**
 * Base for tests against the real MySQL and Redis (CI service containers or the
 * compose stack). The schema is built once per run and every test rolls its rows
 * back. Each test also gets a cache prefix of its own, so no test reads another
 * test's entries, and the stack's cache is never flushed.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'redis',
            'cache.prefix' => sprintf('catalog-test-%s-', bin2hex(random_bytes(4))),
        ]);
        // DDL commits on its own in MySQL, so the schema is built once, before the
        // first transaction, and every test rolls its changes back.
        if (!self::$migrated) {
            $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true]);
            self::$migrated = true;
        }
        $this->database()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->database()->rollBack();
        // PHPUnit keeps every test object, and the last response of a test can still reach
        // this connection through the exception it carries. Closing it here keeps a full run
        // below the max_connections of the stack.
        $this->app->make('db')->disconnect();

        parent::tearDown();
    }

    protected function database(): ConnectionInterface
    {
        return $this->app->make('db')->connection();
    }

    /** @param array<string, mixed> $overrides columns that differ from a valid active book */
    protected function insertProduct(array $overrides = []): void
    {
        $this->database()->table('products')->insert([
            'id' => Uuid::uuid7()->getBytes(),
            'sku' => 'BOOK-TEST-001',
            'name' => 'Test book',
            'store_id' => $this->storeId('arara'),
            'category_id' => $this->database()->table('categories')->where('slug', 'books')->value('id'),
            'status' => 'active',
            'price_cents' => 1000,
            'currency' => 'BRL',
            'weight_grams' => 500,
            'length_mm' => 200,
            'width_mm' => 150,
            'height_mm' => 20,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides columns that differ from a valid store */
    protected function insertStore(array $overrides = []): void
    {
        $this->database()->table('stores')->insert([
            'id' => Uuid::uuid7()->getBytes(),
            'slug' => 'urutau',
            'name' => 'Urutau Ferramentas',
            'tagline' => 'Ferramentas para a oficina de casa.',
            'palette' => 'sabia',
            ...$overrides,
        ]);
    }

    /** The binary id of a store, the way products point at it. */
    protected function storeId(string $slug): string
    {
        $id = $this->database()->table('stores')->where('slug', $slug)->value('id');
        self::assertIsString($id, "Store {$slug} is not in the database.");

        return $id;
    }

    /** The slug of the store a product is in, straight from MySQL. */
    protected function storeOf(string $sku): string
    {
        return (string) $this->database()->table('products AS p')
            ->join('stores AS s', 's.id', '=', 'p.store_id')
            ->where('p.sku', $sku)
            ->value('s.slug');
    }

    protected function productRow(string $sku): stdClass
    {
        $row = $this->database()->table('products')->where('sku', $sku)->first();
        self::assertInstanceOf(stdClass::class, $row, "Product {$sku} is not in the database.");

        return $row;
    }

    /** Seconds the cache entry of a key has left, as Redis sees it. */
    protected function cacheTtl(string $key): int
    {
        /** @var RedisManager $redis */
        $redis = $this->app->make('redis');

        return (int) $redis->connection('cache')->command('ttl', [(string) config('cache.prefix') . $key]);
    }
}
