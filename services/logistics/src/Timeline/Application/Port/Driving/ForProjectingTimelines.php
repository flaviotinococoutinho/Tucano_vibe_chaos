<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\Port\Driving;

use Logistics\Timeline\Application\ProjectionOutcome;
use Logistics\Timeline\Application\TimelineNews;

interface ForProjectingTimelines
{
    /** Adds the step to the internal timeline of the shipment; a step already there changes nothing. */
    public function project(TimelineNews $news): ProjectionOutcome;
}
