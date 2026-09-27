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
    public function __construct(
        public ?ShipmentStatus $from,
        public ShipmentStatus $to,
        public DateTimeImmutable $at,
        public ?string $reason = null,
        public ?string $location = null,
    ) {}
}
