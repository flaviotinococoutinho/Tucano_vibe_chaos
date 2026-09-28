<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\Port\Driving\ForRecordingHubScans;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Application\ShipmentProgress;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Transition\Hub;
use Tucano\SharedKernel\Documentation\UseCase;

#[UseCase('UC-SHP-05')]
final readonly class RecordHubScan implements ForRecordingHubScans
{
    public function __construct(private ShipmentProgress $progress) {}

    public function recordHubScan(CarrierReport $report, ?Hub $hub): ProgressOutcome
    {
        return $this->progress->apply(
            $report,
            static fn(Shipment $shipment) => $shipment->recordHubScan($hub, $report->at),
            static fn(Shipment $shipment): bool => $shipment->hasLeftTheHubs(),
        );
    }
}
