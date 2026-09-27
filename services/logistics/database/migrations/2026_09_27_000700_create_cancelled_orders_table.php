<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE cancelled_orders (
                order_id     uuid        PRIMARY KEY,
                cancelled_at timestamptz NOT NULL
            );

            COMMENT ON TABLE cancelled_orders IS 'Paid orders cancelled before their shipment existed: an order.paid that arrives later (a replay from the DLQ) must not ship them.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE cancelled_orders');
    }
};
