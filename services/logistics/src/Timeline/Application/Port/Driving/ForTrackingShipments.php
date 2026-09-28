<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\Port\Driving;

use Logistics\Timeline\Domain\TrackingCodeUnknown;
use Logistics\Timeline\Domain\TrackingView;

interface ForTrackingShipments
{
    /** @throws TrackingCodeUnknown when the page has no such code */
    public function track(string $trackingCode): TrackingView;
}
