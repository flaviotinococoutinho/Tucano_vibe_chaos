<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\UseCase;

use Logistics\Timeline\Application\Port\Driven\ForStoringTimelines;
use Logistics\Timeline\Application\Port\Driving\ForProjectingTimelines;
use Logistics\Timeline\Application\ProjectionOutcome;
use Logistics\Timeline\Application\TimelineNews;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * The internal side of UC-SHP-10: each shipment event becomes a step of the shipment's
 * timeline in MongoDB. The public page has a use case and a consumer group of its own
 * (UpdateTrackingPage), so an outage of one read model never stops the other (ADR 0027).
 */
#[UseCase('UC-SHP-10')]
final readonly class ProjectTimeline implements ForProjectingTimelines
{
    public function __construct(private ForStoringTimelines $timelines) {}

    public function project(TimelineNews $news): ProjectionOutcome
    {
        return $this->timelines->append($news) ? ProjectionOutcome::Applied : ProjectionOutcome::Duplicate;
    }
}
