<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE outbox_messages (
                id           uuid         PRIMARY KEY,
                topic        varchar(120) NOT NULL,
                message_key  varchar(64)  NOT NULL,
                event_type   varchar(120) NOT NULL,
                payload      jsonb        NOT NULL,
                headers      jsonb        NOT NULL DEFAULT '{}'::jsonb,
                occurred_at  timestamptz  NOT NULL,
                published_at timestamptz,
                attempts     integer      NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                last_error   text
            );

            -- The relay reads only what is still pending, oldest first (FOR UPDATE SKIP LOCKED).
            CREATE INDEX outbox_pending_idx ON outbox_messages (occurred_at, id) WHERE published_at IS NULL;

            COMMENT ON TABLE outbox_messages IS 'Transactional outbox: written in the same transaction as the state change.';
            COMMENT ON COLUMN outbox_messages.id IS 'CloudEvent id (UUIDv7).';
            COMMENT ON COLUMN outbox_messages.payload IS 'Full CloudEvent envelope as published to Kafka.';

            CREATE TABLE inbox_messages (
                consumer    varchar(64) NOT NULL,
                message_id  varchar(64) NOT NULL,
                received_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (consumer, message_id)
            );

            COMMENT ON TABLE inbox_messages IS 'Idempotent consumers: a message id is processed once per consumer (Kafka group or webhook).';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE inbox_messages, outbox_messages');
    }
};
