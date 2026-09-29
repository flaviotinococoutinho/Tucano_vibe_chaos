<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use stdClass;
use Tests\TestCase;
use Tucano\Messaging\Kafka\Message;
use Tucano\SharedKernel\Messaging\CloudEvent;

/**
 * The migration that gives every product a store, run the way catalog-migrate runs it at every
 * start (migrate --seed, then catalog:republish) on a catalog from before the stores. DDL commits
 * on its own in MySQL, so these tests cannot hide in a transaction: each one rolls the schema
 * back, migrates it again, and leaves the fresh schema the other tests expect.
 *
 * @phpstan-type ProductRow array{id: string, version: int, category: string, store: string|null}
 */
#[Group('integration')]
final class StoreMigrationTest extends TestCase
{
    private const array STORE_OF_CATEGORY = ['books' => 'arara', 'electronics' => 'bemtevi', 'home' => 'sabia', 'sports' => 'sabia'];

    protected function setUp(): void
    {
        parent::setUp();

        // The catalog as it was: the seeded products, and neither the stores nor their column.
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->artisan('migrate:rollback', ['--step' => 2, '--force' => true]);
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->app->make('db')->disconnect();

        parent::tearDown();
    }

    #[Test]
    public function the_products_from_before_the_stores_move_to_the_store_of_their_category(): void
    {
        self::assertFalse($this->database()->getSchemaBuilder()->hasTable('stores'));
        $before = $this->products();

        $exitCode = $this->artisan('migrate', ['--seed' => true, '--force' => true]);

        self::assertSame(0, $exitCode);
        $after = $this->products();
        self::assertSame(array_keys($before), array_keys($after), 'The migration and the seed must keep the same products.');
        foreach ($after as $sku => $product) {
            self::assertSame($before[$sku]['id'], $product['id'], "{$sku} kept its id.");
            self::assertSame(self::STORE_OF_CATEGORY[$product['category']], $product['store'], "{$sku} is in the store of its category.");
            self::assertSame($before[$sku]['version'] + 1, $product['version'], "{$sku} has a new version.");
        }
        self::assertSame(['arara', 'bemtevi', 'sabia'], $this->database()->table('stores')->orderBy('slug')->pluck('slug')->all());
    }

    #[Test]
    public function the_republish_that_follows_reaches_consumers_that_keep_only_newer_versions(): void
    {
        $this->artisan('migrate', ['--seed' => true, '--force' => true]);

        $this->artisan('catalog:republish');

        $snapshots = array_map(static fn(Message $message): array => CloudEvent::fromJson($message->payload)->data, $this->kafka->delivered());
        self::assertCount(17, $snapshots);
        foreach ($snapshots as $snapshot) {
            // Commerce and logistics already hold version 1 of each of these, without a store.
            self::assertSame(2, $snapshot['version']);
            self::assertSame(self::STORE_OF_CATEGORY[(string) $snapshot['category']], $snapshot['store']);
        }
    }

    #[Test]
    public function a_run_that_stopped_halfway_picks_up_where_it_stopped(): void
    {
        // The first run added the column and stopped before it was recorded as done.
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true]);
        $this->database()->statement('ALTER TABLE products ADD COLUMN store_id BINARY(16) NULL AFTER category_id');

        $exitCode = $this->artisan('migrate', ['--seed' => true, '--force' => true]);

        self::assertSame(0, $exitCode);
        self::assertSame([], array_filter($this->products(), static fn(array $product): bool => $product['store'] === null));
    }

    #[Test]
    public function a_category_without_a_store_stops_the_migration_naming_its_products(): void
    {
        $toys = Uuid::uuid7()->getBytes();
        $this->database()->table('categories')->insert(['id' => $toys, 'slug' => 'toys', 'name' => 'Brinquedos']);
        $this->database()->table('products')->insert([
            'id' => Uuid::uuid7()->getBytes(),
            'sku' => 'TOY-KITE-001',
            'name' => 'Pipa',
            'category_id' => $toys,
            'status' => 'active',
            'price_cents' => 2990,
            'currency' => 'BRL',
            'weight_grams' => 150,
            'length_mm' => 600,
            'width_mm' => 600,
            'height_mm' => 10,
        ]);

        $this->expectExceptionObject(new RuntimeException('No store for TOY-KITE-001: give their category a store in STORE_OF_CATEGORY.'));

        $this->artisan('migrate', ['--force' => true]);
    }

    /** @return array<string, ProductRow> by SKU; the store is null before the stores */
    private function products(): array
    {
        $hasStores = $this->database()->getSchemaBuilder()->hasColumn('products', 'store_id');
        $query = $this->database()->table('products AS p')
            ->join('categories AS c', 'c.id', '=', 'p.category_id')
            ->selectRaw('p.sku, BIN_TO_UUID(p.id) AS id, p.version, c.slug AS category')
            ->orderBy('p.sku');
        if ($hasStores) {
            $query->leftJoin('stores AS s', 's.id', '=', 'p.store_id')->addSelect('s.slug AS store');
        }

        $products = [];
        foreach ($query->get() as $row) {
            /** @var stdClass $row */
            $products[(string) $row->sku] = [
                'id' => (string) $row->id,
                'version' => (int) $row->version,
                'category' => (string) $row->category,
                'store' => $hasStores && $row->store !== null ? (string) $row->store : null,
            ];
        }

        return $products;
    }

    private function database(): Connection
    {
        return $this->app->make('db')->connection();
    }
}
