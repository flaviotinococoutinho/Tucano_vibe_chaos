<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\Port\Driving\ForRecordingDeliveryOutcomes;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Application\ShipmentProgress;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use Tucano\SharedKernel\Documentation\UseCase;

/** A visit ends one way or the other; the proof or the reason goes to delivery_attempts with it. */
#[UseCase('UC-SHP-07')]
final readonly class RecordDeliveryOutcome implements ForRecordingDeliveryOutcomes
{
    public function __construct(private ShipmentProgress $progress) {}

    public function recordDelivery(CarrierReport $report, ?ProofOfDelivery $proof): ProgressOutcome
    {
        return $this->progress->apply($report, static fn(Shipment $shipment) => $shipment->recordDelivery($proof, $report->at));
    }

    public function recordFailedVisit(CarrierReport $report, ?DeliveryFailure $failure): ProgressOutcome
    {
        return $this->progress->apply($report, static fn(Shipment $shipment) => $shipment->recordFailedAttempt($failure, $report->at));
    }
}
