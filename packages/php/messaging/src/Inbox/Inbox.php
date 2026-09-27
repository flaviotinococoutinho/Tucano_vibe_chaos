<?php

declare(strict_types=1);

namespace Tucano\Messaging\Inbox;

use PDO;

/**
 * Idempotent consumer. Call it inside the same transaction as the side effect:
 * if the effect rolls back, the inbox row rolls back too and the redelivery
 * is processed again.
 */
final readonly class Inbox
{
    public function __construct(private PDO $connection) {}

    /** True the first time this consumer sees the message, false for every duplicate. */
    public function firstTime(string $consumer, string $messageId): bool
    {
        $statement = $this->connection->prepare(
            'INSERT INTO inbox_messages (consumer, message_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
        );
        $statement->execute([$consumer, $messageId]);

        return $statement->rowCount() === 1;
    }
}
