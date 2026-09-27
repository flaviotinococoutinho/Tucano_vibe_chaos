<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Domain;

/**
 * Aggregates record what happened; the application layer releases the events
 * and stores them in the outbox inside the same transaction as the state.
 */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    /** @return list<DomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    protected function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
