<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;
use Logistics\Shipping\Domain\Error\TransitionRefused;

interface ForReturningToSender
{
    /**
     * The parcels start the way back to the fulfillment center.
     *
     * @throws TransitionNotAllowed when the last visit did not fail
     * @throws TransitionRefused when there are visits left and nobody refused the parcels
     */
    public function startReturn(CarrierReport $report): ProgressOutcome;

    /** @throws TransitionNotAllowed when the parcels were not on their way back */
    public function completeReturn(CarrierReport $report): ProgressOutcome;
}
