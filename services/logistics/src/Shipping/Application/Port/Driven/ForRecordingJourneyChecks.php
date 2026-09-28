<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use DateTimeImmutable;
use Logistics\Shipping\Application\JourneyResult;
use Logistics\Shipping\Domain\Shipment\ShipmentId;

/** What each reconciliation round found, kept for the watch of stalled journeys. */
interface ForRecordingJourneyChecks
{
    public function record(ShipmentId $shipment, JourneyResult $result, DateTimeImmutable $at): void;
}
