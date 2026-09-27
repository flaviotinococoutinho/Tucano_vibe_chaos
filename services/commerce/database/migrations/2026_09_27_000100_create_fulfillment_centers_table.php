<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE fulfillment_centers (
                code  char(4)     PRIMARY KEY CHECK (code ~ '^[A-Z]{3}[0-9]$'),
                name  varchar(80) NOT NULL,
                state char(2)     NOT NULL CHECK (state ~ '^[A-Z]{2}$')
            );

            COMMENT ON TABLE fulfillment_centers IS 'Warehouses as Inventory sees them: where stock lives.';
            COMMENT ON COLUMN fulfillment_centers.code IS 'Fixed 4-char code, e.g. GRU1.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE fulfillment_centers');
    }
};
