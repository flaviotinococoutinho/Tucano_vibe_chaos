<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\Port\Driven;

use Logistics\Timeline\Application\TimelineNews;

/** The public page of each tracking code, read by key. */
interface ForPublishingTrackingViews
{
    /** @return bool false when the page already had this step */
    public function append(TimelineNews $news): bool;
}
