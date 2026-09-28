<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application;

use Logistics\Timeline\Domain\Place;
use Logistics\Timeline\Domain\TimelineStep;

/**
 * What one event of logistics.shipments.v2 adds to the read side: the step,
 * and, from shipment.created only, the carrier and where the parcels go. The
 * event id keeps a redelivered event from adding the same step twice.
 */
final readonly class TimelineNews
{
    private function __construct(
        public string $shipmentId,
        public string $orderId,
        public string $trackingCode,
        public string $eventId,
        public TimelineStep $step,
        public ?string $carrier,
        public ?Place $destination,
    ) {}

    public static function of(string $shipmentId, string $orderId, string $trackingCode, string $eventId, TimelineStep $step, ?string $carrier = null, ?Place $destination = null): self
    {
        return new self($shipmentId, $orderId, $trackingCode, $eventId, $step, $carrier, $destination);
    }
}
