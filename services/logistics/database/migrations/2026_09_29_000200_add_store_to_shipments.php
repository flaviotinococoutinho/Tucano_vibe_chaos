<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0031: a shipment belongs to the store of its order. The shipments from before the
 * stores keep a null store, and no store shows them.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE shipments
                ADD COLUMN store varchar(31) CONSTRAINT shipments_store_check CHECK (store ~ '^[a-z][a-z0-9-]{1,30}$');

            COMMENT ON COLUMN shipments.store IS 'Slug of the store the shipment belongs to (ADR 0031), from order.paid or, for an order from before the stores, from its products; null for a shipment from before the stores or whose store was still unknown. Every shipment event carries it.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE shipments DROP COLUMN store');
    }
};
