<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\UseCase;

use Logistics\Timeline\Application\Port\Driven\ForPublishingTrackingViews;
use Logistics\Timeline\Application\Port\Driven\ForStoringTimelines;
use Logistics\Timeline\Application\Port\Driving\ForProjectingTimelines;
use Logistics\Timeline\Application\ProjectionOutcome;
use Logistics\Timeline\Application\TimelineNews;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * The write side of UC-SHP-10: each shipment event becomes a step in two read
 * models, the timeline in MongoDB and the public page in DynamoDB. There is no
 * transaction across them. Each one skips a step it already has, so a failure
 * between the two is fixed by the redelivery: the first says duplicate, the
 * second catches up.
 */
#[UseCase('UC-SHP-10')]
final readonly class ProjectTimeline implements ForProjectingTimelines
{
    public function __construct(private ForStoringTimelines $timelines, private ForPublishingTrackingViews $pages) {}

    public function project(TimelineNews $news): ProjectionOutcome
    {
        $stored = $this->timelines->append($news);
        $published = $this->pages->append($news);

        return $stored || $published ? ProjectionOutcome::Applied : ProjectionOutcome::Duplicate;
    }
}
