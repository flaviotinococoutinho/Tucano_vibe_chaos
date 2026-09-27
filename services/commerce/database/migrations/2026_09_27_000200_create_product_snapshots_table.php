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
                price_cents     bigint       NOT NULL CHECK (price_cents >= 0),
                currency        char(3)      NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                status          varchar(16)  NOT NULL CHECK (status IN ('active', 'discontinued')),
                catalog_version bigint       NOT NULL CHECK (catalog_version > 0),
                synced_at       timestamptz  NOT NULL DEFAULT now()
            );

            COMMENT ON TABLE product_snapshots IS 'Local copy of catalog.products.v1 (compacted topic): checkout never calls the catalog.';
            COMMENT ON COLUMN product_snapshots.catalog_version IS 'Version sent by the catalog; older versions are ignored when events arrive out of order.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE product_snapshots');
    }
};
