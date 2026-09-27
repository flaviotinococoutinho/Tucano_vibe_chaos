<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Two payment attempts at the same time share one payment, so the provider sees one key.
            CREATE UNIQUE INDEX payments_one_pending_per_order ON payments (order_id) WHERE status = 'pending';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX payments_one_pending_per_order');
    }
};
