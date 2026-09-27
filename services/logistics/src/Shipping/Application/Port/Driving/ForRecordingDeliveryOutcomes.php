<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;
use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;

interface ForRecordingDeliveryOutcomes
{
    /**
     * @throws TransitionNotAllowed when no courier is out with the parcels
     * @throws TransitionRefused when the proof of delivery is missing
     */
    public function recordDelivery(CarrierReport $report, ?ProofOfDelivery $proof): ProgressOutcome;

    /**
     * @throws TransitionNotAllowed when no courier is out with the parcels
     * @throws TransitionRefused when the reason is missing
     */
    public function recordFailedVisit(CarrierReport $report, ?DeliveryFailure $failure): ProgressOutcome;
}
