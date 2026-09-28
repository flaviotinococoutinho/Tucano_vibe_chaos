<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\Port\Driven;

use Logistics\Timeline\Domain\TrackingView;

interface ForReadingTrackingViews
{
    public function find(string $trackingCode): ?TrackingView;
}
