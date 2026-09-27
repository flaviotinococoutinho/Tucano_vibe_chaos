<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE products (
                id           BINARY(16)      NOT NULL COMMENT 'UUIDv7: time ordered, so new rows land at the end of the clustered index',
                id_text      CHAR(36)        GENERATED ALWAYS AS (BIN_TO_UUID(id)) VIRTUAL COMMENT 'Readable id for ad hoc queries, computed on read and never stored',
                sku          VARCHAR(32)     NOT NULL,
                name         VARCHAR(160)    NOT NULL,
                category_id  BINARY(16)      NOT NULL,
                status       ENUM('draft', 'active', 'discontinued') NOT NULL DEFAULT 'draft' COMMENT 'Drafts are never published',
                price_cents  BIGINT          NOT NULL,
                currency     CHAR(3)         NOT NULL DEFAULT 'BRL',
                weight_grams INT UNSIGNED    NOT NULL,
                length_mm    INT UNSIGNED    NOT NULL,
                width_mm     INT UNSIGNED    NOT NULL,
                height_mm    INT UNSIGNED    NOT NULL,
                version      BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Bumped on every change; consumers drop versions older than the one they hold',
                created_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at   DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                CONSTRAINT products_sku_unique UNIQUE (sku),
                CONSTRAINT products_category_fk FOREIGN KEY (category_id) REFERENCES categories (id),
                CONSTRAINT products_sku_format CHECK (REGEXP_LIKE(sku, '^[A-Z0-9][A-Z0-9-]{2,31}$', 'c')),
                CONSTRAINT products_price_not_negative CHECK (price_cents >= 0),
                CONSTRAINT products_currency_format CHECK (REGEXP_LIKE(currency, '^[A-Z]{3}$', 'c')),
                CONSTRAINT products_size_positive CHECK (weight_grams > 0 AND length_mm > 0 AND width_mm > 0 AND height_mm > 0),
                INDEX products_by_category (category_id, status)
            ) COMMENT = 'Products and prices, source of catalog.products.v1'
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE products');
    }
};
