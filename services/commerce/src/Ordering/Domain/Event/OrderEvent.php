<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Event;

use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Commerce\Ordering\Domain\Store\StoreSlug;
use DateTimeImmutable;
use Tucano\SharedKernel\Domain\DomainEvent;
use Tucano\SharedKernel\Identity\EventId;

/** What every order event carries; subclasses add their own facts. */
abstract readonly class OrderEvent implements DomainEvent
{
    private string $eventId;

    public function __construct(
        private OrderId $orderId,
        private OrderNumber $orderNumber,
        private ?StoreSlug $store,
        private DateTimeImmutable $occurredAt,
    ) {
        $this->eventId = EventId::generate()->toString();
    }

    final public function eventId(): string
    {
        return $this->eventId;
    }

    final public function eventType(): string
    {
        return 'tucano.commerce.order.' . $this->fact();
    }

    final public function aggregateId(): string
    {
        return $this->orderId->toString();
    }

    final public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    final public function payload(): array
    {
        return [
            'orderId' => $this->orderId->toString(),
            'orderNumber' => (string) $this->orderNumber,
            // Every fact of an order says its store (ADR 0031). An order placed before the stores
            // has none, and its events leave the field out, as the contracts expect.
            ...($this->store === null ? [] : ['store' => (string) $this->store]),
            ...$this->details(),
        ];
    }

    /** Past tense fact, e.g. "paid". */
    abstract protected function fact(): string;

    /** @return array<string, mixed> */
    abstract protected function details(): array;
}
