<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\Port\Driving\ForReturningToSender;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Application\ShipmentProgress;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Tucano\SharedKernel\Documentation\UseCase;

/** The ReturnAllowed guard decides: after the last visit, or after a refusal. */
#[UseCase('UC-SHP-08')]
final readonly class ReturnToSender implements ForReturningToSender
{
    public function __construct(private ShipmentProgress $progress) {}

    public function startReturn(CarrierReport $report): ProgressOutcome
    {
        return $this->progress->apply($report, static fn(Shipment $shipment) => $shipment->returnToSender($report->at));
    }

    public function completeReturn(CarrierReport $report): ProgressOutcome
    {
        return $this->progress->apply($report, static fn(Shipment $shipment) => $shipment->recordReturn($report->at));
    }
}
