<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Order\OrderId;
use DateTimeImmutable;

/** What Logistics told about the shipment of an order: which event, which order, and when it happened. */
final readonly class ShipmentNews
{
    private function __construct(public string $eventId, public OrderId $orderId, public DateTimeImmutable $at) {}

    public static function of(string $eventId, OrderId $orderId, DateTimeImmutable $at): self
    {
        return new self($eventId, $orderId, $at);
    }
}
