<?php

declare(strict_types=1);

namespace Logistics\Timeline\Domain;

use DateTimeImmutable;

/**
 * One step of the journey on the tracking page: what happened, when, and the
 * detail that helps the customer (the hub it went through, the visit, why the
 * visit failed).
 */
final readonly class TimelineStep
{
    private function __construct(
        public JourneyStatus $status,
        public DateTimeImmutable $at,
        public ?string $hub,
        public ?int $attempt,
        public ?string $reason,
    ) {}

    public static function of(JourneyStatus $status, DateTimeImmutable $at, ?string $hub = null, ?int $attempt = null, ?string $reason = null): self
    {
        return new self($status, $at, $hub, $attempt, $reason);
    }

    /** @return array{status: string, at: string, hub?: string, attempt?: int, reason?: string} */
    public function toArray(): array
    {
        return array_filter(
            ['status' => $this->status->value, 'at' => $this->at->format(DATE_RFC3339_EXTENDED), 'hub' => $this->hub, 'attempt' => $this->attempt, 'reason' => $this->reason],
            static fn(string|int|null $value): bool => $value !== null,
        );
    }
}
