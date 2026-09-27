<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE shipments (
                id                 uuid         PRIMARY KEY DEFAULT uuidv7(),
                tracking_code      bigint       NOT NULL UNIQUE,
                order_id           uuid         NOT NULL UNIQUE,
                status             varchar(24)  NOT NULL CHECK (status IN (
                                       'created', 'ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery',
                                       'delivered', 'delivery_failed', 'returning', 'returned', 'cancelled')),
                carrier_code       varchar(32)  NOT NULL REFERENCES carriers (code),
                origin             char(4)      NOT NULL REFERENCES fulfillment_centers (code),
                recipient_name     varchar(120) NOT NULL,
                recipient_email    varchar(254) NOT NULL,
                dest_street        varchar(160) NOT NULL,
                dest_number        varchar(16)  NOT NULL,
                dest_complement    varchar(80),
                dest_district      varchar(80)  NOT NULL,
                dest_city          varchar(80)  NOT NULL,
                dest_state         char(2)      NOT NULL CHECK (dest_state ~ '^[A-Z]{2}$'),
                dest_postal_code   char(8)      NOT NULL CHECK (dest_postal_code ~ '^[0-9]{8}$'),
                dest_latitude      numeric(9,6) CHECK (dest_latitude BETWEEN -90 AND 90),
                dest_longitude     numeric(9,6) CHECK (dest_longitude BETWEEN -180 AND 180),
                total_weight_grams integer      NOT NULL CHECK (total_weight_grams > 0),
                delivery_attempts  smallint     NOT NULL DEFAULT 0 CHECK (delivery_attempts BETWEEN 0 AND 3),
                label_object_key   varchar(200),
                courier_id         uuid,
                created_at         timestamptz  NOT NULL,
                updated_at         timestamptz  NOT NULL,
                version            integer      NOT NULL DEFAULT 1 CHECK (version > 0),
                -- Same rule as the LabelMustBeAttached guard: nothing leaves the warehouse without a label.
                CONSTRAINT label_before_pickup CHECK (status IN ('created', 'cancelled') OR label_object_key IS NOT NULL)
            );

            CREATE INDEX shipments_status_idx ON shipments (status, updated_at);

            COMMENT ON COLUMN shipments.tracking_code IS 'Snowflake; shown to people as TX + 13 Crockford Base32 symbols (CHAR(15)).';
            COMMENT ON COLUMN shipments.order_id IS 'UNIQUE: a repeated OrderPaid event can never create a second shipment.';
            COMMENT ON COLUMN shipments.status IS 'Shipment state machine; see docs/architecture/state-machines.md.';

            CREATE TABLE parcels (
                shipment_id   uuid     NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
                parcel_number smallint NOT NULL CHECK (parcel_number > 0),
                weight_grams  integer  NOT NULL CHECK (weight_grams > 0),
                length_mm     integer  NOT NULL CHECK (length_mm > 0),
                width_mm      integer  NOT NULL CHECK (width_mm > 0),
                height_mm     integer  NOT NULL CHECK (height_mm > 0),
                PRIMARY KEY (shipment_id, parcel_number)
            );

            CREATE TABLE shipment_transitions (
                id          uuid         PRIMARY KEY DEFAULT uuidv7(),
                shipment_id uuid         NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
                from_status varchar(24),
                to_status   varchar(24)  NOT NULL,
                reason      varchar(120),
                location    varchar(120),
                metadata    jsonb        NOT NULL DEFAULT '{}'::jsonb,
                occurred_at timestamptz  NOT NULL
            );

            CREATE INDEX shipment_transitions_shipment_idx ON shipment_transitions (shipment_id, occurred_at);

            COMMENT ON TABLE shipment_transitions IS 'Append-only history of the shipment state machine; dates like delivered_at come from here.';

            CREATE TABLE delivery_attempts (
                id                uuid         PRIMARY KEY,
                shipment_id       uuid         NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
                attempt_number    smallint     NOT NULL CHECK (attempt_number BETWEEN 1 AND 3),
                outcome           varchar(16)  NOT NULL CHECK (outcome IN ('delivered', 'failed', 'refused')),
                reason            varchar(120),
                receiver_name     varchar(120),
                receiver_document varchar(20),
                occurred_at       timestamptz  NOT NULL,
                UNIQUE (shipment_id, attempt_number),
                CONSTRAINT proof_of_delivery CHECK (outcome <> 'delivered' OR receiver_name IS NOT NULL)
            );

            COMMENT ON COLUMN delivery_attempts.id IS 'Sent by the courier app: resending the same attempt is idempotent.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE delivery_attempts, shipment_transitions, parcels, shipments');
    }
};
