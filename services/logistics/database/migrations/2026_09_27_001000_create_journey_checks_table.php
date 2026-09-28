<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- One row per shipment: what the last reconciliation round (UC-SHP-12) found, and
            -- since when it keeps finding the same. The watch of stalled journeys (UC-SHP-13)
            -- reads it to say why a shipment stopped: unknown to the carrier, a silent carrier,
            -- a carrier that does not answer, or a step the machine refused.
            CREATE TABLE journey_checks (
                shipment_id  uuid        PRIMARY KEY REFERENCES shipments (id) ON DELETE CASCADE,
                last_result  varchar(24) NOT NULL CHECK (last_result IN (
                                 'caught_up', 'up_to_date', 'unknown_to_carrier', 'carrier_unreachable', 'stopped')),
                result_since timestamptz NOT NULL,
                checked_at   timestamptz NOT NULL CHECK (checked_at >= result_since),
                rounds       integer     NOT NULL CHECK (rounds > 0)
            );

            COMMENT ON COLUMN journey_checks.result_since IS 'When the current run of the same result began.';
            COMMENT ON COLUMN journey_checks.rounds IS 'Rounds in the current run of the same result.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE journey_checks');
    }
};
