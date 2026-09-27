<?php

declare(strict_types=1);

namespace Commerce\Shared\Application\Port\Driven;

use Tucano\SharedKernel\Domain\DomainEvent;

/** Hands domain events to the outbox, inside the transaction of the caller. */
interface ForPublishingEvents
{
    public function publish(DomainEvent ...$events): void;
}
