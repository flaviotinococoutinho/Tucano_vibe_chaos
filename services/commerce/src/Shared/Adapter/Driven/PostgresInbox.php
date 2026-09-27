<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driven;

use Commerce\Shared\Application\Port\Driven\ForDeduplicatingMessages;
use Illuminate\Database\Connection;
use Tucano\Messaging\Inbox\Inbox;

final readonly class PostgresInbox implements ForDeduplicatingMessages
{
    public function __construct(private Connection $connection) {}

    public function firstTime(string $consumer, string $messageId): bool
    {
        return new Inbox($this->connection->getPdo())->firstTime($consumer, $messageId);
    }
}
