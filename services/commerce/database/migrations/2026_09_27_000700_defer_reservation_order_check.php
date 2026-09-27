<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_reservations
                ALTER CONSTRAINT stock_reservations_order_id_fkey DEFERRABLE INITIALLY DEFERRED;

            COMMENT ON CONSTRAINT stock_reservations_order_id_fkey ON stock_reservations IS
                'Inventory holds stock before Ordering stores the order, in the same transaction; the check waits for COMMIT.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE stock_reservations ALTER CONSTRAINT stock_reservations_order_id_fkey NOT DEFERRABLE');
    }
};
