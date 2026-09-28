<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- UC-SHP-12 claims the shipment in the hands of a carrier quiet for the longest: a partial
            -- index on the statuses of ShipmentStatus::awaitsCarrier() answers ORDER BY updated_at
            -- LIMIT 1 without sorting, and stays small because delivered and returned leave it.
            CREATE INDEX shipments_awaiting_carrier_idx ON shipments (updated_at)
                WHERE status IN ('ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery', 'delivery_failed', 'returning');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX shipments_awaiting_carrier_idx');
    }
};
