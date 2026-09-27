<?php

declare(strict_types=1);

namespace Tests\Doubles\Shared;

use Commerce\Shared\Application\Port\Driven\ForPublishingEvents;
use Tucano\SharedKernel\Domain\DomainEvent;

final class RecordedEvents implements ForPublishingEvents
{
    /** @var list<DomainEvent> */
    public private(set) array $events = [];

    public function publish(DomainEvent ...$events): void
    {
        array_push($this->events, ...$events);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_map(static fn(DomainEvent $event): string => $event->eventType(), $this->events);
    }
}
