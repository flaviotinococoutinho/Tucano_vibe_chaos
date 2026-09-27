<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE product_snapshots (
                product_id      uuid         PRIMARY KEY,
                sku             varchar(32)  NOT NULL UNIQUE,
                name            varchar(160) NOT NULL,
                weight_grams    integer      NOT NULL CHECK (weight_grams > 0),
                length_mm       integer      NOT NULL CHECK (length_mm > 0),
                width_mm        integer      NOT NULL CHECK (width_mm > 0),
                height_mm       integer      NOT NULL CHECK (height_mm > 0),
                catalog_version bigint       NOT NULL CHECK (catalog_version > 0),
                synced_at       timestamptz  NOT NULL DEFAULT now()
            );

            COMMENT ON TABLE product_snapshots IS 'Weight and size of each product, from catalog.products.v1; used to build parcels.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE product_snapshots');
    }
};
