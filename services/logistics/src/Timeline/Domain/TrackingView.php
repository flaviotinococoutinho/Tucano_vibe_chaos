<?php

declare(strict_types=1);

namespace Logistics\Timeline\Domain;

use DateTimeImmutable;

/**
 * The public page of a tracking code: the status, the carrier, where the
 * parcels go and the steps so far, oldest first. Built from the events, so it
 * can lag the shipment by the time the projection takes (BASE, ADR 0012).
 */
final readonly class TrackingView
{
    /** @param list<TimelineStep> $steps */
    private function __construct(
        public string $trackingCode,
        public JourneyStatus $status,
        public ?string $carrier,
        public ?Place $destination,
        public DateTimeImmutable $updatedAt,
        public array $steps,
    ) {}

    /** @param list<TimelineStep> $steps */
    public static function of(string $trackingCode, JourneyStatus $status, ?string $carrier, ?Place $destination, DateTimeImmutable $updatedAt, array $steps): self
    {
        return new self($trackingCode, $status, $carrier, $destination, $updatedAt, $steps);
    }

    /** @return array{trackingCode: string, status: string, carrier: ?string, destination: ?array{municipality: string, state: string}, updatedAt: string, steps: list<array<string, int|string>>} */
    public function toArray(): array
    {
        return [
            'trackingCode' => $this->trackingCode,
            'status' => $this->status->value,
            'carrier' => $this->carrier,
            'destination' => $this->destination?->toArray(),
            'updatedAt' => $this->updatedAt->format(DATE_RFC3339_EXTENDED),
            'steps' => array_map(static fn(TimelineStep $step): array => $step->toArray(), $this->steps),
        ];
    }
}
