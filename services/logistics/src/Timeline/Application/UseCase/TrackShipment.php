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
}
