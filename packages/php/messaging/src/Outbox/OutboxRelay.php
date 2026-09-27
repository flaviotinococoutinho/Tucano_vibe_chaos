<?php

declare(strict_types=1);

namespace Tucano\Messaging\Outbox;

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
 */
final readonly class OutboxRelay
{
    public function __construct(
        private PDO $connection,
        private Producer $producer,
        private int $batchSize = 100,
    ) {}

    /** @return int how many messages were published */
    public function relayBatch(): int
    {
        $this->connection->beginTransaction();
        try {
            $rows = $this->lockPendingBatch();
            $this->publish($rows);
            $this->markPublished(array_column($rows, 'id'));
            $this->connection->commit();
        } catch (Throwable $failure) {
            $this->connection->rollBack();
            $this->recordFailure($failure);

            throw $failure;
        }

        return count($rows);
    }

    /** @return list<array{id: string, topic: string, message_key: string, payload: string, headers: string}> */
    private function lockPendingBatch(): array
    {
        $statement = $this->connection->prepare(<<<'SQL'
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
    private function markPublished(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->connection
            ->prepare("UPDATE outbox_messages SET published_at = now(), attempts = attempts + 1 WHERE id IN ({$placeholders})")
            ->execute($ids);
    }

    private function recordFailure(Throwable $failure): void
    {
        try {
            $this->connection
                ->prepare(<<<'SQL'
                    UPDATE outbox_messages SET attempts = attempts + 1, last_error = :error
                    WHERE id IN (
                        SELECT id FROM outbox_messages WHERE published_at IS NULL
                        ORDER BY occurred_at, id LIMIT :limit
                    )
                SQL)
                ->execute(['error' => mb_substr($failure->getMessage(), 0, 1_000), 'limit' => $this->batchSize]);
        } catch (Throwable) {
            // Best effort: the database may be the very reason the batch failed,
            // and the original failure is the one worth rethrowing.
        }
    }
}
