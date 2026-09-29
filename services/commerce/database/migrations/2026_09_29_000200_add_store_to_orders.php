<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0031: an order is of one store only, and a store reads only its own orders. The orders
 * placed before the stores keep no store, so no store shows them; every new order has one,
 * which the domain requires, because a CHECK here would also stop the old orders from moving on.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders
                ADD COLUMN store varchar(31),
                ADD CONSTRAINT orders_store_check CHECK (store ~ '^[a-z][a-z0-9-]{1,30}$');

            COMMENT ON COLUMN orders.store IS 'Slug of the store the order was placed in (ADR 0031); null only for orders placed before the stores.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE orders DROP COLUMN store;');
    }
};
