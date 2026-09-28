<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UC-ORD-04: the order keeps the public code of its shipment, learned when the
 * carrier picks the parcels up, so the customer goes from the order straight to
 * the tracking page.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders
                ADD COLUMN tracking_code char(15),
                ADD CONSTRAINT orders_tracking_code_check CHECK (tracking_code ~ '^TX[0-9A-HJKMNP-TV-Z]{13}$'),
                -- Only an order that left the fulfillment center has a shipment code. The other way
                -- round does not hold: orders shipped before this column have none, and Logistics is
                -- where their codes live.
                ADD CONSTRAINT orders_tracking_code_once_shipped_check
                    CHECK (tracking_code IS NULL OR status IN ('shipped', 'delivered', 'returned'));

            COMMENT ON COLUMN orders.tracking_code IS 'Public code of the shipment (TX plus 13 Crockford Base32 symbols), set when the order ships.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE orders DROP COLUMN tracking_code;');
    }
};
