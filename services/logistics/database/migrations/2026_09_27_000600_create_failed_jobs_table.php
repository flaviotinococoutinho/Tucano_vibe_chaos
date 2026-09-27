<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE failed_jobs (
                id         bigint      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid       uuid        NOT NULL UNIQUE,
                connection text        NOT NULL,
                queue      text        NOT NULL,
                payload    text        NOT NULL,
                exception  text        NOT NULL,
                failed_at  timestamptz NOT NULL DEFAULT now()
            );

            COMMENT ON TABLE failed_jobs IS 'Owned by the Laravel queue (label jobs on SQS); the only table with an identity key, because the framework expects one.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE failed_jobs');
    }
};
