<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE payments (
                id                 uuid         PRIMARY KEY DEFAULT uuidv7(),
                order_id           uuid         NOT NULL REFERENCES orders (id),
                status             varchar(24)  NOT NULL CHECK (status IN (
                                       'pending', 'captured', 'failed', 'refund_requested', 'refunded')),
                amount_cents       bigint       NOT NULL CHECK (amount_cents >= 0),
                currency           char(3)      NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
                provider           varchar(32)  NOT NULL DEFAULT 'payfake',
                provider_charge_id varchar(64)  UNIQUE,
                failure_reason     varchar(120),
                created_at         timestamptz  NOT NULL,
                updated_at         timestamptz  NOT NULL
            );

            -- At most one successful payment per order, enforced by the database even if the code slips.
            CREATE UNIQUE INDEX payments_one_success_per_order ON payments (order_id)
                WHERE status IN ('captured', 'refund_requested', 'refunded');
            -- The reconciliation job scans only payments still waiting for the provider.
            CREATE INDEX payments_pending_idx ON payments (created_at) WHERE status = 'pending';

            COMMENT ON COLUMN payments.id IS 'Also sent to the provider as Idempotency-Key.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE payments');
    }
};
