<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Doubles;

use PDO;
use PHPUnit\Framework\TestCase;

/** Real PostgreSQL in a throwaway schema, with the same DDL the services migrate. */
final class Postgres
{
    /** A connection to a fresh schema: the tables of the outbox and the inbox, empty. */
    public static function connect(): PDO
    {
        $pdo = self::pdo();
        $pdo->exec(<<<'SQL'
            DROP SCHEMA IF EXISTS messaging_tests CASCADE;
            CREATE SCHEMA messaging_tests;
            SET search_path TO messaging_tests;
            CREATE TABLE outbox_messages (
                id           uuid         PRIMARY KEY,
                topic        varchar(120) NOT NULL,
                message_key  varchar(64)  NOT NULL,
                event_type   varchar(120) NOT NULL,
                payload      jsonb        NOT NULL,
                headers      jsonb        NOT NULL DEFAULT '{}'::jsonb,
                occurred_at  timestamptz  NOT NULL,
                published_at timestamptz,
                attempts     integer      NOT NULL DEFAULT 0,
                last_error   text
            );
            CREATE TABLE inbox_messages (
                consumer    varchar(64) NOT NULL,
                message_id  varchar(64) NOT NULL,
                received_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (consumer, message_id)
            );
        SQL);

        return $pdo;
    }

    /** Another connection to the schema connect() created, as a process opening its own would. */
    public static function open(): PDO
    {
        $pdo = self::pdo();
        $pdo->exec('SET search_path TO messaging_tests');

        return $pdo;
    }

    private static function pdo(): PDO
    {
        $dsn = (string) getenv('MESSAGING_PG_DSN');
        if ($dsn === '') {
            TestCase::markTestSkipped('Set MESSAGING_PG_DSN (and MESSAGING_PG_USER/PASSWORD) to run the PostgreSQL tests.');
        }

        return new PDO($dsn, (string) getenv('MESSAGING_PG_USER'), (string) getenv('MESSAGING_PG_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
