<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Doubles;

use DateTimeImmutable;
use Tucano\SharedKernel\Domain\DomainEvent;

final readonly class OrderPaid implements DomainEvent
{
    public function __construct(
        private string $eventId,
        private string $orderId,
        private DateTimeImmutable $paidAt,
    ) {}

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function eventType(): string
    {
        return 'tucano.commerce.order.paid';
    }

    public function aggregateId(): string
    {
        return $this->orderId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function payload(): array
    {
        return ['orderId' => $this->orderId, 'orderNumber' => '97663530295234560'];
    }
}
