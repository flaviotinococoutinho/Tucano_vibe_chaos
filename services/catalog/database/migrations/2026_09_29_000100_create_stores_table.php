<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stores (
                id         BINARY(16)   NOT NULL COMMENT 'UUIDv7',
                slug       VARCHAR(31)  NOT NULL COMMENT 'Never changes: it is the address of the store and the store of every event',
                name       VARCHAR(80)  NOT NULL,
                tagline    VARCHAR(120) NOT NULL,
                palette    ENUM('arara', 'bemtevi', 'sabia') NOT NULL COMMENT 'A palette of the web design system, which owns its colors',
                created_at DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (id),
                CONSTRAINT stores_slug_unique UNIQUE (slug),
                CONSTRAINT stores_slug_format CHECK (REGEXP_LIKE(slug, '^[a-z][a-z0-9-]{1,30}$', 'c'))
            ) COMMENT = 'Stores the platform hosts (ADR 0031); every product belongs to one'
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE stores');
    }
};
