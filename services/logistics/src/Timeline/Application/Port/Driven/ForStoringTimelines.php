<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\Port\Driven;

use Logistics\Timeline\Application\TimelineNews;

/** The whole journey of each shipment, for the people of the operation. */
interface ForStoringTimelines
{
    /** @return bool false when the timeline already had this step */
    public function append(TimelineNews $news): bool;
}
