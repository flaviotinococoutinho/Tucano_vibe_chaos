<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0031: every product belongs to one store. The copy learns it from the snapshots on
 * catalog.products.v1, so a product stays without a store until a snapshot that says it arrives.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE product_snapshots
                ADD COLUMN store varchar(31) CONSTRAINT product_snapshots_store_check CHECK (store ~ '^[a-z][a-z0-9-]{1,30}$');

            COMMENT ON COLUMN product_snapshots.store IS 'Slug of the store the product belongs to (ADR 0031); null until a snapshot with the store arrives. A shipment of an order from before the stores takes its store from here.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE product_snapshots DROP COLUMN store');
    }
};
