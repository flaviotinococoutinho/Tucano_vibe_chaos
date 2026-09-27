<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE fulfillment_centers (
                code          char(4)      PRIMARY KEY CHECK (code ~ '^[A-Z]{3}[0-9]$'),
                name          varchar(80)  NOT NULL,
                city          varchar(80)  NOT NULL,
                state         char(2)      NOT NULL CHECK (state ~ '^[A-Z]{2}$'),
                latitude      numeric(9,6) NOT NULL CHECK (latitude BETWEEN -90 AND 90),
                longitude     numeric(9,6) NOT NULL CHECK (longitude BETWEEN -180 AND 180),
                pickup_cutoff time         NOT NULL
            );

            COMMENT ON TABLE fulfillment_centers IS 'Warehouses as Logistics sees them: a point on the map with a pickup schedule.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE fulfillment_centers');
    }
};
