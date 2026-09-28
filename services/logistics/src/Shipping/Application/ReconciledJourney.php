<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Logistics\Shipping\Domain\Shipment\TrackingCode;

/** One shipment put side by side with the history of its carrier, and what came of it. */
final readonly class ReconciledJourney
{
    private function __construct(
        public TrackingCode $trackingCode,
        public JourneyResult $result,
        public int $applied,
        public ?string $refusal,
    ) {}

    public static function caughtUp(TrackingCode $trackingCode, int $applied): self
    {
        return new self($trackingCode, JourneyResult::CaughtUp, $applied, null);
    }

    public static function upToDate(TrackingCode $trackingCode): self
    {
        return new self($trackingCode, JourneyResult::UpToDate, 0, null);
    }

    public static function carrierUnreachable(TrackingCode $trackingCode): self
    {
        return new self($trackingCode, JourneyResult::CarrierUnreachable, 0, null);
    }

    public static function stopped(TrackingCode $trackingCode, int $applied, string $refusal): self
    {
        return new self($trackingCode, JourneyResult::Stopped, $applied, $refusal);
    }
}
