<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stock_items (
                sku                varchar(32) NOT NULL,
                fulfillment_center char(4)     NOT NULL REFERENCES fulfillment_centers (code),
                on_hand            integer     NOT NULL CHECK (on_hand >= 0),
                reserved           integer     NOT NULL DEFAULT 0 CHECK (reserved >= 0),
                version            bigint      NOT NULL DEFAULT 0,
                updated_at         timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (sku, fulfillment_center),
                CONSTRAINT reserved_within_on_hand CHECK (reserved <= on_hand)
            );

            COMMENT ON COLUMN stock_items.reserved IS 'Held for unpaid orders; the CHECK is the last line of defense against overselling.';
            COMMENT ON COLUMN stock_items.version IS 'Used only by the optimistic reservation strategy.';

            CREATE TABLE stock_reservations (
                id                 uuid        PRIMARY KEY DEFAULT uuidv7(),
                order_id           uuid        NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
                sku                varchar(32) NOT NULL,
                fulfillment_center char(4)     NOT NULL,
                quantity           integer     NOT NULL CHECK (quantity > 0),
                status             varchar(16) NOT NULL CHECK (status IN ('active', 'committed', 'released')),
                expires_at         timestamptz NOT NULL,
                created_at         timestamptz NOT NULL,
                FOREIGN KEY (sku, fulfillment_center) REFERENCES stock_items (sku, fulfillment_center)
            );

            CREATE INDEX stock_reservations_order_idx ON stock_reservations (order_id);
            CREATE INDEX stock_reservations_active_idx ON stock_reservations (sku, fulfillment_center) WHERE status = 'active';

            COMMENT ON TABLE stock_reservations IS 'Invariant checked by the overselling lab: sum of active reservations = stock_items.reserved.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE stock_reservations, stock_items');
    }
};
