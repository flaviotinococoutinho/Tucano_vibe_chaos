<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\Port\Driven;

use Logistics\Timeline\Domain\TrackingPagesUnavailable;
use Logistics\Timeline\Domain\TrackingView;

interface ForReadingTrackingViews
{
    /** @throws TrackingPagesUnavailable when the pages cannot be read right now */
    public function find(string $trackingCode): ?TrackingView;
}
