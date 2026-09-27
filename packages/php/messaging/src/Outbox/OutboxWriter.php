<?php

declare(strict_types=1);

namespace Tucano\Messaging\Outbox;

use PDO;
use Tucano\SharedKernel\Messaging\CloudEvent;

/**
 * Stores the event in outbox_messages using the caller's connection, so it
 * commits (or rolls back) together with the state change that produced it.
 */
final readonly class OutboxWriter
{
    public function __construct(private PDO $connection) {}

    public function append(string $topic, CloudEvent $event): void
    {
        $statement = $this->connection->prepare(<<<'SQL'
            INSERT INTO outbox_messages (id, topic, message_key, event_type, payload, headers, occurred_at)
            VALUES (:id, :topic, :message_key, :event_type, :payload, :headers, :occurred_at)
        SQL);

        $statement->execute([
            'id' => $event->id,
            'topic' => $topic,
            'message_key' => $event->subject,
            'event_type' => $event->type,
            'payload' => $event->toJson(),
            'headers' => json_encode(['correlation_id' => $event->correlationId], JSON_THROW_ON_ERROR),
            'occurred_at' => $event->time->format(DATE_RFC3339_EXTENDED),
        ]);
    }
}
