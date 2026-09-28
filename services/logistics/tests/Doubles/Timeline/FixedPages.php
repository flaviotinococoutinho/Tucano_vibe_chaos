<?php

declare(strict_types=1);

namespace Tests\Doubles\Timeline;

use Logistics\Timeline\Application\Port\Driven\ForReadingTrackingViews;
use Logistics\Timeline\Domain\TrackingView;

final readonly class FixedPages implements ForReadingTrackingViews
{
    /** @param array<string, TrackingView> $pages keyed by tracking code */
    public function __construct(private array $pages) {}

    public function find(string $trackingCode): ?TrackingView
    {
        return $this->pages[$trackingCode] ?? null;
    }
}
