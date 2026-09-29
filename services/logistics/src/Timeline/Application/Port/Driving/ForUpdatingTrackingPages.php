<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\Port\Driving;

use Logistics\Timeline\Application\ProjectionOutcome;
use Logistics\Timeline\Application\TimelineNews;

interface ForUpdatingTrackingPages
{
    /** Adds the step to the public page of the tracking code; a step already there changes nothing. */
    public function update(TimelineNews $news): ProjectionOutcome;
}
