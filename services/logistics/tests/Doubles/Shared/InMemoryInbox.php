<?php

declare(strict_types=1);

namespace Tests\Doubles\Shared;

use Logistics\Shared\Application\Port\Driven\ForDeduplicatingMessages;

final class InMemoryInbox implements ForDeduplicatingMessages
{
    /** @var array<string, true> */
    private array $seen = [];

    public function firstTime(string $consumer, string $messageId): bool
    {
        $key = $consumer . ' ' . $messageId;
        if (isset($this->seen[$key])) {
            return false;
        }
        $this->seen[$key] = true;

        return true;
    }
}
