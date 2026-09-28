<?php

declare(strict_types=1);

namespace Tests\Doubles\Timeline;

use Logistics\Timeline\Application\Port\Driven\ForPublishingTrackingViews;
use Logistics\Timeline\Application\Port\Driven\ForStoringTimelines;
use Logistics\Timeline\Application\TimelineNews;

/** A read model that remembers the events it took, and says duplicate for one it has. */
final class RecordedSteps implements ForStoringTimelines, ForPublishingTrackingViews
{
    /** @var list<TimelineNews> */
    public private(set) array $taken = [];

    /** @var array<string, true> */
    private array $seen = [];

    /** A step that got here before, like one written just before the process died. */
    public function alreadyHas(string $eventId): void
    {
        $this->seen[$eventId] = true;
    }

    public function append(TimelineNews $news): bool
    {
        if (isset($this->seen[$news->eventId])) {
            return false;
        }
        $this->seen[$news->eventId] = true;
        $this->taken[] = $news;

        return true;
    }
}
