<?php

declare(strict_types=1);

namespace Logistics\Shared\Application\Port\Driven;

/**
 * The inbox of the idempotent consumers. Called inside the transaction of the
 * effect: when the effect rolls back, the mark rolls back with it, and the
 * redelivered message is handled again.
 */
interface ForDeduplicatingMessages
{
    /** True the first time the consumer sees the message, false for every repeat. */
    public function firstTime(string $consumer, string $messageId): bool;
}
