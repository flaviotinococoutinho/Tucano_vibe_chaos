<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;

interface ForRecordingPickups
{
    /**
     * The carrier collected the parcels at the fulfillment center.
     *
     * @throws TransitionNotAllowed when the shipment is not waiting for pickup
     */
    public function recordPickup(CarrierReport $report): ProgressOutcome;
}
