<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\Port\Driving\ForDispatchingDeliveries;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Application\ShipmentProgress;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Tucano\SharedKernel\Documentation\UseCase;

#[UseCase('UC-SHP-06')]
final readonly class DispatchForDelivery implements ForDispatchingDeliveries
{
    public function __construct(private ShipmentProgress $progress) {}

    public function dispatch(CarrierReport $report): ProgressOutcome
    {
        return $this->progress->apply($report, static fn(Shipment $shipment) => $shipment->sendOutForDelivery($report->at));
    }
}
