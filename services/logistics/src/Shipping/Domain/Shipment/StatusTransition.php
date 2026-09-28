<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use DateTimeImmutable;

/**
 * One step of the state machine, kept for the append-only history: the reason
 * of a failure or a cancellation, and the hub where a scan happened.
 */
final readonly class StatusTransition
{
    private function __construct(
        public ?ShipmentStatus $from,
        public ShipmentStatus $to,
        public DateTimeImmutable $at,
        public ?string $reason = null,
        public ?string $location = null,
    ) {}

    /** The status the aggregate is born in: there is nothing before it. */
    public static function initial(ShipmentStatus $status, DateTimeImmutable $at): self
    {
        return new self(null, $status, $at);
    }

    public static function between(ShipmentStatus $from, ShipmentStatus $to, DateTimeImmutable $at, ?string $reason = null, ?string $location = null): self
    {
        return new self($from, $to, $at, $reason, $location);
    }
}
