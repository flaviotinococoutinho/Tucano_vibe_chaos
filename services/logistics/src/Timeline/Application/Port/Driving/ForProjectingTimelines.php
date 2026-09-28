<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\Port\Driving;

use Logistics\Timeline\Application\ProjectionOutcome;
use Logistics\Timeline\Application\TimelineNews;

interface ForProjectingTimelines
{
    /** Adds the step to the timeline and to the public page; a step already there changes nothing. */
    public function project(TimelineNews $news): ProjectionOutcome;
}
