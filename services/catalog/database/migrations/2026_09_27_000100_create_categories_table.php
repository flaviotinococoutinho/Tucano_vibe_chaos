<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE categories (
                id         BINARY(16)  NOT NULL COMMENT 'UUIDv7',
                slug       VARCHAR(64) NOT NULL,
                name       VARCHAR(80) NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                CONSTRAINT categories_slug_unique UNIQUE (slug),
                CONSTRAINT categories_slug_format CHECK (REGEXP_LIKE(slug, '^[a-z0-9]+(-[a-z0-9]+)*$', 'c'))
            ) COMMENT = 'Product categories, a flat list'
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE categories');
    }
};
