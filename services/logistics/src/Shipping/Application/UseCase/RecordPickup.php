<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\Port\Driving\ForRecordingPickups;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Application\ShipmentProgress;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Tucano\SharedKernel\Documentation\UseCase;

#[UseCase('UC-SHP-04')]
final readonly class RecordPickup implements ForRecordingPickups
{
    public function __construct(private ShipmentProgress $progress) {}

    public function recordPickup(CarrierReport $report): ProgressOutcome
    {
        return $this->progress->apply($report, static fn(Shipment $shipment) => $shipment->recordPickup($report->at));
    }
}
