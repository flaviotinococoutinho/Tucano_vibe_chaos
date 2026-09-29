<?php

declare(strict_types=1);

namespace Logistics\Timeline\Domain;

use DateTimeImmutable;

/**
 * The public page of a tracking code: the store the shipment belongs to, the
 * status, the carrier, where the parcels go and the steps so far, oldest first.
 * Built from the events, so it can lag the shipment by the time the projection
 * takes (BASE, ADR 0012).
 */
final readonly class TrackingView
{
    /** @param list<TimelineStep> $steps */
    private function __construct(
        public string $trackingCode,
        public ?string $store,
        public JourneyStatus $status,
        public ?string $carrier,
        public ?Place $destination,
        public DateTimeImmutable $updatedAt,
        public array $steps,
    ) {}

    /** @param list<TimelineStep> $steps */
    public static function of(string $trackingCode, ?string $store, JourneyStatus $status, ?string $carrier, ?Place $destination, DateTimeImmutable $updatedAt, array $steps): self
    {
        return new self($trackingCode, $store, $status, $carrier, $destination, $updatedAt, $steps);
    }

    /** A page from before the stores belongs to none (ADR 0031). */
    public function belongsTo(string $store): bool
    {
        return $this->store === $store;
    }

    /** @return array{trackingCode: string, store: ?string, status: string, carrier: ?string, destination: ?array{municipality: string, state: string}, updatedAt: string, steps: list<array<string, int|string>>} */
    public function toArray(): array
    {
        return [
            'trackingCode' => $this->trackingCode,
            'store' => $this->store,
            'status' => $this->status->value,
            'carrier' => $this->carrier,
            'destination' => $this->destination?->toArray(),
            'updatedAt' => $this->updatedAt->format(DATE_RFC3339_EXTENDED),
            'steps' => array_map(static fn(TimelineStep $step): array => $step->toArray(), $this->steps),
        ];
    }
}
