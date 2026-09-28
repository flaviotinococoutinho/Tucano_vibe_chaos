<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application\UseCase;

use Logistics\Timeline\Application\Port\Driven\ForPublishingTrackingViews;
use Logistics\Timeline\Application\Port\Driving\ForUpdatingTrackingPages;
use Logistics\Timeline\Application\ProjectionOutcome;
use Logistics\Timeline\Application\TimelineNews;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * The public side of UC-SHP-10: each shipment event becomes a step of the page people read
 * by tracking code, in DynamoDB. It reads the events in a consumer group of its own, apart
 * from the internal timeline, so an outage of MongoDB never stops the page (ADR 0027).
 */
#[UseCase('UC-SHP-10')]
final readonly class UpdateTrackingPage implements ForUpdatingTrackingPages
{
    public function __construct(private ForPublishingTrackingViews $pages) {}

    public function update(TimelineNews $news): ProjectionOutcome
    {
        return $this->pages->append($news) ? ProjectionOutcome::Applied : ProjectionOutcome::Duplicate;
    }
}
