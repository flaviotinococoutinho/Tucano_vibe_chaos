<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\Port\Driving;

use Logistics\Timeline\Domain\TrackingCodeUnknown;
use Logistics\Timeline\Domain\TrackingPagesUnavailable;
use Logistics\Timeline\Domain\TrackingView;

interface ForTrackingShipments
{
    /**
     * The page of any store, or of a shipment from before the stores: how the platform finds
     * which store a code belongs to.
     *
     * @throws TrackingCodeUnknown when the page has no such code
     * @throws TrackingPagesUnavailable when the pages cannot be read right now
     */
    public function track(string $trackingCode): TrackingView;

    /**
     * The page only when it belongs to the store (ADR 0031).
     *
     * @throws TrackingCodeUnknown when the page has no such code, or belongs to another store or to none
     * @throws TrackingPagesUnavailable when the pages cannot be read right now
     */
    public function trackInStore(string $store, string $trackingCode): TrackingView;
}
