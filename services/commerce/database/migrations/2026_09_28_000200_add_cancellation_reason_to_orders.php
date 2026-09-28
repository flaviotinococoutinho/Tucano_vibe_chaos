<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UC-ORD-05: a cancelled order says why it stopped, so the customer reads "the
 * payment was declined" instead of a bare "cancelled". The reason was already in
 * the history; now it is part of the state too.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders ADD COLUMN cancellation_reason varchar(32);

            -- Orders cancelled before this column take the reason of the move that cancelled them.
            UPDATE orders AS o
               SET cancellation_reason = t.reason
              FROM order_status_transitions AS t
             WHERE t.order_id = o.id
               AND t.to_status = 'cancelled'
               AND o.status = 'cancelled';

            ALTER TABLE orders
                ADD CONSTRAINT orders_cancellation_reason_check
                    CHECK (cancellation_reason IN ('payment_declined', 'reservation_expired', 'customer_request')),
                -- Both ways: a cancelled order always knows why, and only a cancelled order has a reason.
                ADD CONSTRAINT orders_cancellation_reason_when_cancelled_check
                    CHECK ((status = 'cancelled') = (cancellation_reason IS NOT NULL));

            COMMENT ON COLUMN orders.cancellation_reason IS 'Why a cancelled order stopped: payment_declined, reservation_expired or customer_request.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE orders DROP COLUMN cancellation_reason;');
    }
};
