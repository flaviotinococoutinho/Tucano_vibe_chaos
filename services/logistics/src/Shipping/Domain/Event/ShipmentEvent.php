<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Event;

use DateTimeImmutable;
use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Logistics\Shipping\Domain\Shipment\StatusTransition;
use Ramsey\Uuid\Uuid;
use Tucano\SharedKernel\Domain\DomainEvent;

/**
 * What every shipment event carries. The type comes from the status the
 * transition reached, so the names on logistics.shipments.v2 are exactly the
 * states of the machine; subclasses add their own facts.
 */
abstract readonly class ShipmentEvent implements DomainEvent
{
    private string $eventId;

    public function __construct(private ShipmentReference $shipment, protected StatusTransition $transition)
    {
        $this->eventId = Uuid::uuid7()->toString();
    }

    final public function eventId(): string
    {
        return $this->eventId;
    }

    final public function eventType(): string
    {
        return 'tucano.logistics.shipment.' . $this->transition->to->value;
    }

    final public function aggregateId(): string
    {
        return $this->shipment->id->toString();
    }

    final public function occurredAt(): DateTimeImmutable
    {
        return $this->transition->at;
    }

    final public function payload(): array
    {
        return [
            'shipmentId' => $this->shipment->id->toString(),
            'trackingCode' => (string) $this->shipment->trackingCode,
            'orderId' => $this->shipment->orderId->toString(),
            ...$this->details(),
        ];
    }

    /** @return array<string, mixed> */
    abstract protected function details(): array;
}
