<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\UseCase;

use Logistics\Timeline\Application\Port\Driven\ForReadingTrackingViews;
use Logistics\Timeline\Application\Port\Driving\ForTrackingShipments;
use Logistics\Timeline\Domain\TrackingCodeUnknown;
use Logistics\Timeline\Domain\TrackingView;
use Tucano\SharedKernel\Documentation\UseCase;

/** UC-SHP-10, the read: one key lookup on the public page, never a query on the shipments database. */
#[UseCase('UC-SHP-10')]
final readonly class TrackShipment implements ForTrackingShipments
{
    public function __construct(private ForReadingTrackingViews $pages) {}

    public function track(string $trackingCode): TrackingView
    {
        return $this->pages->find(strtoupper(trim($trackingCode))) ?? throw TrackingCodeUnknown::code($trackingCode);
    }

    /**
     * A store sees only its own pages, and the page itself says whose it is. The page of
     * another store, or of a shipment from before the stores, gets the very answer of a code
     * nobody knows, so asking through one store never tells that a code exists in another.
     */
    public function trackInStore(string $store, string $trackingCode): TrackingView
    {
        $page = $this->track($trackingCode);

        return $page->belongsTo($store) ? $page : throw TrackingCodeUnknown::code($trackingCode);
    }
}
