<?php

declare(strict_types=1);

namespace Commerce\Shared\Application\Port\Driven;

/** The inbox: a message from outside is handled once per consumer, however many times it arrives. */
interface ForDeduplicatingMessages
{
    /** Records the message inside the running transaction; false when it was already handled. */
    public function firstTime(string $consumer, string $messageId): bool;
}
