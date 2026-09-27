<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- abandoned: Tucano gave up waiting, with no charge at the provider and the order no longer waiting.
            ALTER TABLE payments DROP CONSTRAINT payments_status_check;
            ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN (
                'pending', 'abandoned', 'captured', 'failed', 'refund_requested', 'refunded'));

            -- The reconciliation claims, among the payments still missing the provider's final word,
            -- the one untouched for the longest. An abandoned payment is in only if a charge turned up.
            DROP INDEX payments_pending_idx;
            CREATE INDEX payments_unsettled_idx ON payments (updated_at)
                WHERE status IN ('pending', 'refund_requested') OR (status = 'abandoned' AND provider_charge_id IS NOT NULL);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX payments_unsettled_idx;
            CREATE INDEX payments_pending_idx ON payments (created_at) WHERE status = 'pending';

            ALTER TABLE payments DROP CONSTRAINT payments_status_check;
            ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN (
                'pending', 'captured', 'failed', 'refund_requested', 'refunded'));
        SQL);
    }
};
