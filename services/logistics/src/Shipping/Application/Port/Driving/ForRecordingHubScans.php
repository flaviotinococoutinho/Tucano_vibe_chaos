<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;
use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Transition\Hub;

interface ForRecordingHubScans
{
    /**
     * A sorting hub of the carrier scanned the parcels on their way.
     *
     * @throws TransitionNotAllowed when the parcels were not picked up yet
     * @throws TransitionRefused when the hub is missing
     */
    public function recordHubScan(CarrierReport $report, ?Hub $hub): ProgressOutcome;
}
