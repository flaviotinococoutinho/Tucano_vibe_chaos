<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\StalledJourneys;

interface ForWatchingStalledJourneys
{
    /** Looks for journeys stalled past the limit and raises an alert when there is any. */
    public function watch(): StalledJourneys;
}
