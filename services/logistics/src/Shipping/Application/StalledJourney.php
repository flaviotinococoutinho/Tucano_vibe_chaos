<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A shipment in the hands of a carrier that stopped moving: where it stopped,
 * since when, and what the last reconciliation round found about it. The
 * times are kept in UTC, the way the alert shows them.
 */
final readonly class StalledJourney
{
    private function __construct(
        public string $trackingCode,
        public string $status,
        public string $carrier,
        public DateTimeImmutable $lastStepAt,
        public ?JourneyResult $lastCheck,
        public ?DateTimeImmutable $lastCheckSince,
    ) {}

    public static function of(string $trackingCode, string $status, string $carrier, DateTimeImmutable $lastStepAt, ?JourneyResult $lastCheck = null, ?DateTimeImmutable $lastCheckSince = null): self
    {
        $utc = new DateTimeZone('UTC');

        return new self($trackingCode, $status, $carrier, $lastStepAt->setTimezone($utc), $lastCheck, $lastCheckSince?->setTimezone($utc));
    }

    /** Why it stopped, in the words of the last reconciliation round. */
    public function reason(): string
    {
        return match ($this->lastCheck) {
            null => 'never compared with the carrier yet',
            JourneyResult::UnknownToCarrier => 'the carrier does not know it',
            JourneyResult::UpToDate => 'the carrier has no news either',
            JourneyResult::CarrierUnreachable => 'the carrier does not answer',
            JourneyResult::Stopped => 'the state machine refused a step of the carrier history',
            JourneyResult::CaughtUp => 'it caught up with the carrier and stopped again',
        };
    }

    /** One line of the alert: TX02... picked_up with correio-nacional since 2026-09-27 21:10 UTC: the carrier does not know it. */
    public function toLine(): string
    {
        $line = sprintf('%s %s with %s since %s: %s', $this->trackingCode, $this->status, $this->carrier, $this->lastStepAt->format('Y-m-d H:i T'), $this->reason());

        return $this->lastCheckSince === null ? $line : sprintf('%s, the same answer since %s', $line, $this->lastCheckSince->format('Y-m-d H:i T'));
    }
}
