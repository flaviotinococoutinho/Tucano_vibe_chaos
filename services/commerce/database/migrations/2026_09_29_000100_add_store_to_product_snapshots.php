<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0031: every product belongs to one store, and checkout only takes the products of the
 * store the order is placed in. The copy learns the store from catalog.products.v1; until a
 * snapshot says it, the product belongs to no store, and no order can have it.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE product_snapshots
                ADD COLUMN store varchar(31),
                ADD CONSTRAINT product_snapshots_store_check CHECK (store ~ '^[a-z][a-z0-9-]{1,30}$');

            COMMENT ON COLUMN product_snapshots.store IS 'Slug of the store the product belongs to (ADR 0031); null until a snapshot of the catalog says it.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE product_snapshots DROP COLUMN store;');
    }
};
