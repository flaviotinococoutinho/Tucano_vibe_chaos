<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;
use Logistics\Shipping\Domain\Error\TransitionRefused;

interface ForDispatchingDeliveries
{
    /**
     * A courier left with the parcels for the address: the first visit, or another one.
     *
     * @throws TransitionNotAllowed when the parcels are not at a point that can go out
     * @throws TransitionRefused when the visits ran out
     */
    public function dispatch(CarrierReport $report): ProgressOutcome;
}
