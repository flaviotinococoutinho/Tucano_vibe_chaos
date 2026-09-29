<?php

declare(strict_types=1);

namespace Tests\Doubles\Timeline;

use Logistics\Timeline\Application\Port\Driven\ForReadingTrackingViews;
use Logistics\Timeline\Domain\TrackingView;

/** Pages the test can take away between two requests, to compare an answer with the one for a code nobody knows. */
final class InMemoryPages implements ForReadingTrackingViews
{
    /** @var array<string, TrackingView> keyed by tracking code */
    private array $pages = [];

    public function put(TrackingView $page): self
    {
        $this->pages[$page->trackingCode] = $page;

        return $this;
    }

    public function forget(string $trackingCode): void
    {
        unset($this->pages[$trackingCode]);
    }

    public function find(string $trackingCode): ?TrackingView
    {
        return $this->pages[$trackingCode] ?? null;
    }
}
