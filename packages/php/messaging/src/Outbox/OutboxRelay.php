<?php

declare(strict_types=1);

namespace Tucano\Messaging\Outbox;

use Closure;
use PDO;
use Throwable;
use Tucano\Messaging\Kafka\Message;
use Tucano\Messaging\Kafka\Producer;
use Tucano\SharedKernel\Messaging\CloudEvent;

/**
 * Polling publisher. Locks a batch with FOR UPDATE SKIP LOCKED (so a second
 * relay would take the next batch instead of waiting), publishes, waits for
 * Kafka to acknowledge and only then marks the batch as published. A crash in
 * between means the batch is published again: at-least-once, never lost.
 *
 * The connection comes from a factory. A batch that fails drops it, and the
 * next batch opens a new one, because the failure may have been the connection
 * itself: a restart or a failover of the database, or a proxy that reset it.
 */
final class OutboxRelay
{
    private ?PDO $connection = null;

    /** @param Closure(): PDO $connect opens a connection in ERRMODE_EXCEPTION */
    public function __construct(
        private readonly Closure $connect,
        private readonly Producer $producer,
        private readonly int $batchSize = 100,
    ) {}

    /** @return int how many messages were published */
    public function relayBatch(): int
    {
        $connection = $this->connection ??= ($this->connect)();
        try {
            $connection->beginTransaction();
            $rows = $this->lockPendingBatch($connection);
            $this->publish($rows);
            $this->markPublished($connection, array_column($rows, 'id'));
            $connection->commit();
        } catch (Throwable $failure) {
            $this->giveUpBatch($connection, $failure);

            throw $failure;
        }

        return count($rows);
    }

    /** Rolls back and notes the failure as far as the connection still allows, then lets it go. */
    private function giveUpBatch(PDO $connection, Throwable $failure): void
    {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $this->recordFailure($connection, $failure);
        } catch (Throwable) {
            // Best effort: the database may be the very reason the batch failed,
            // and the original failure is the one worth rethrowing.
        }
        $this->connection = null;
    }

    /** @return list<array{id: string, topic: string, message_key: string, payload: string, headers: string}> */
    private function lockPendingBatch(PDO $connection): array
    {
        $statement = $connection->prepare(<<<'SQL'
            SELECT id, topic, message_key, payload, headers
            FROM outbox_messages
            WHERE published_at IS NULL
            ORDER BY occurred_at, id
            LIMIT :limit
            FOR UPDATE SKIP LOCKED
        SQL);
        $statement->bindValue('limit', $this->batchSize, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array{id: string, topic: string, message_key: string, payload: string, headers: string}> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array{id: string, topic: string, message_key: string, payload: string, headers: string}> $rows */
    private function publish(array $rows): void
    {
        foreach ($rows as $row) {
            /** @var array<string, string> $headers */
            $headers = json_decode($row['headers'], true, 512, JSON_THROW_ON_ERROR);
            $this->producer->send(new Message($row['topic'], $row['message_key'], $row['payload'], [
                'content-type' => CloudEvent::CONTENT_TYPE,
                ...$headers,
            ]));
        }
        if ($rows !== []) {
            $this->producer->flush();
        }
    }

    /** @param list<string> $ids */
    private function markPublished(PDO $connection, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $connection
            ->prepare("UPDATE outbox_messages SET published_at = now(), attempts = attempts + 1 WHERE id IN ({$placeholders})")
            ->execute($ids);
    }

    private function recordFailure(PDO $connection, Throwable $failure): void
    {
        $connection
            ->prepare(<<<'SQL'
                UPDATE outbox_messages SET attempts = attempts + 1, last_error = :error
                WHERE id IN (
                    SELECT id FROM outbox_messages WHERE published_at IS NULL
                    ORDER BY occurred_at, id LIMIT :limit
                )
            SQL)
            ->execute(['error' => mb_substr($failure->getMessage(), 0, 1_000), 'limit' => $this->batchSize]);
    }
}
