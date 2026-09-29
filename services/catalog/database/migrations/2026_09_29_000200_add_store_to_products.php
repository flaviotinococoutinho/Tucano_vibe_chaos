<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Every product belongs to one store (docs/adr/0031-a-store-is-a-tenant.md). The products from
 * before the stores move to the store of their category in three steps: the column arrives
 * empty, every row gets its store, and only then does the column become required.
 */
return new class extends Migration {
    /**
     * The stores those products move to, as they opened. They live here and not only in
     * StoreSeeder because the seeders run after every migration; the seeder keeps them current.
     */
    private const array FOUNDING_STORES = [
        'arara' => ['Arara Livros', 'Livros para quem constrói sistemas.', 'arara'],
        'bemtevi' => ['Bem-te-vi Eletrônicos', 'Eletrônicos para a mesa de trabalho.', 'bemtevi'],
        'sabia' => ['Sabiá Casa e Esporte', 'Da cozinha ao treino, o que o dia pede.', 'sabia'],
    ];

    /** category => the store that sells it */
    private const array STORE_OF_CATEGORY = [
        'books' => 'arara',
        'electronics' => 'bemtevi',
        'home' => 'sabia',
        'sports' => 'sabia',
    ];

    public function up(): void
    {
        // MySQL commits each DDL statement on its own, so a run that stops halfway leaves the
        // column behind. Looking for it first lets the next run pick up where this one stopped.
        if (!DB::getSchemaBuilder()->hasColumn('products', 'store_id')) {
            DB::unprepared('ALTER TABLE products ADD COLUMN store_id BINARY(16) NULL AFTER category_id');
        }
        $this->openFoundingStores();
        $this->moveProductsToTheStoreOfTheirCategory();
        $this->ensureEveryProductHasAStore();

        // The index serves the store listing: status, then name, then the primary key that
        // InnoDB appends to every secondary index, which is the order of the page.
        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                MODIFY store_id BINARY(16) NOT NULL,
                ADD INDEX products_by_store (store_id, status, name),
                ADD CONSTRAINT products_store_fk FOREIGN KEY (store_id) REFERENCES stores (id)
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE products DROP FOREIGN KEY products_store_fk');
        DB::unprepared('ALTER TABLE products DROP INDEX products_by_store, DROP COLUMN store_id');
    }

    /** Only the missing ones: a run that stopped halfway may have opened them already. */
    private function openFoundingStores(): void
    {
        foreach (self::FOUNDING_STORES as $slug => [$name, $tagline, $palette]) {
            DB::insert(
                'INSERT INTO stores (id, slug, name, tagline, palette) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                [Uuid::uuid7()->getBytes(), $slug, $name, $tagline, $palette],
            );
        }
    }

    /**
     * Gaining a store is a change of the product, so the version goes up. Commerce and logistics
     * keep a snapshot only when it is newer than their copy, and the one catalog:republish sends
     * next, with the store, has to be.
     */
    private function moveProductsToTheStoreOfTheirCategory(): void
    {
        foreach (self::STORE_OF_CATEGORY as $category => $store) {
            DB::update(<<<'SQL'
                UPDATE products
                SET store_id = (SELECT id FROM stores WHERE slug = ?),
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP(6)
                WHERE store_id IS NULL
                  AND category_id = (SELECT id FROM categories WHERE slug = ?)
                SQL, [$store, $category]);
        }
    }

    /** A category without a store would fail the NOT NULL with a message that names no product. */
    private function ensureEveryProductHasAStore(): void
    {
        $homeless = DB::table('products')->whereNull('store_id')->orderBy('sku')->pluck('sku')->all();
        if ($homeless !== []) {
            throw new RuntimeException(sprintf(
                'No store for %s: give their category a store in STORE_OF_CATEGORY.',
                implode(', ', $homeless),
            ));
        }
    }
};
