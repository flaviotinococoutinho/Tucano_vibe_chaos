<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use DateTimeImmutable;
use Logistics\Shipping\Domain\Shipment\TrackingCode;

/** What a carrier told about a shipment: which event, about which label, and when it happened. */
final readonly class CarrierReport
{
    private function __construct(
        public string $eventId,
        public TrackingCode $trackingCode,
        public DateTimeImmutable $at,
    ) {}

    public static function of(string $eventId, TrackingCode $trackingCode, DateTimeImmutable $at): self
    {
        return new self($eventId, $trackingCode, $at);
    }
}
