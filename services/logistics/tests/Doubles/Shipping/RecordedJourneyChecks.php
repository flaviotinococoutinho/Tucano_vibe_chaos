<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use DateTimeImmutable;
use Logistics\Shipping\Application\JourneyResult;
use Logistics\Shipping\Application\Port\Driven\ForRecordingJourneyChecks;
use Logistics\Shipping\Domain\Shipment\ShipmentId;

final class RecordedJourneyChecks implements ForRecordingJourneyChecks
{
    /** @var list<array{string, JourneyResult}> the shipment id and the result of each round */
    public private(set) array $rounds = [];

    public function record(ShipmentId $shipment, JourneyResult $result, DateTimeImmutable $at): void
    {
        $this->rounds[] = [$shipment->toString(), $result];
    }
}
