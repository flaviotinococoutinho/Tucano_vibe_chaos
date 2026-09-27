<?php

declare(strict_types=1);

namespace Logistics\Shared\Adapter\Driven;

use Illuminate\Database\Connection;
use Logistics\Shared\Application\Port\Driven\ForDeduplicatingMessages;
use Tucano\Messaging\Inbox\Inbox;

/**
 * inbox_messages through the connection of the running transaction, so the
 * mark commits or rolls back together with the effect of the message.
 */
final readonly class PostgresInbox implements ForDeduplicatingMessages
{
    public function __construct(private Connection $connection) {}

    public function firstTime(string $consumer, string $messageId): bool
    {
        return new Inbox($this->connection->getPdo())->firstTime($consumer, $messageId);
    }
}
