<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Event;

use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderNumber;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Tucano\SharedKernel\Domain\DomainEvent;

/** What every order event carries; subclasses add their own facts. */
abstract readonly class OrderEvent implements DomainEvent
{
    private string $eventId;

    public function __construct(
        private OrderId $orderId,
        private OrderNumber $orderNumber,
        private DateTimeImmutable $occurredAt,
    ) {
        $this->eventId = Uuid::uuid7()->toString();
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
            ...$this->details(),
        ];
    }

    /** Past tense fact, e.g. "paid". */
    abstract protected function fact(): string;

    /** @return array<string, mixed> */
    abstract protected function details(): array;
}
