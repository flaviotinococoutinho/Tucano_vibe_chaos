<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\TrackingCode;
use DateTimeImmutable;

/** What Logistics told about the shipment of an order: which event, which order, which shipment by its code, and when it happened. */
final readonly class ShipmentNews
{
    private function __construct(
        public string $eventId,
        public OrderId $orderId,
        public TrackingCode $trackingCode,
        public DateTimeImmutable $at,
    ) {}

    public static function of(string $eventId, OrderId $orderId, TrackingCode $trackingCode, DateTimeImmutable $at): self
    {
        return new self($eventId, $orderId, $trackingCode, $at);
    }
}
