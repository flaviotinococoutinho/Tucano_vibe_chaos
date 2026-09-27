<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE carriers (
                code             varchar(32) PRIMARY KEY CHECK (code ~ '^[a-z0-9-]+$'),
                name             varchar(80) NOT NULL,
                kind             varchar(16) NOT NULL CHECK (kind IN ('own_fleet', 'partner')),
                max_weight_grams integer     NOT NULL CHECK (max_weight_grams > 0)
            );

            COMMENT ON TABLE carriers IS 'Reference data; the selection rules live in the Chain of Responsibility in code.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE carriers');
    }
};
