<?php

declare(strict_types=1);

namespace Tests\Unit\Timeline;

use DateTimeImmutable;
use Logistics\Timeline\Application\ProjectionOutcome;
use Logistics\Timeline\Application\TimelineNews;
use Logistics\Timeline\Application\UseCase\ProjectTimeline;
use Logistics\Timeline\Application\UseCase\TrackShipment;
use Logistics\Timeline\Application\UseCase\UpdateTrackingPage;
use Logistics\Timeline\Domain\JourneyStatus;
use Logistics\Timeline\Domain\Place;
use Logistics\Timeline\Domain\TimelineStep;
use Logistics\Timeline\Domain\TrackingCodeUnknown;
use Logistics\Timeline\Domain\TrackingView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\Timeline\FixedPages;
use Tests\Doubles\Timeline\RecordedSteps;

final class ProjectTimelineTest extends TestCase
{
    #[Test]
    public function a_step_goes_to_the_timeline_once(): void
    {
        $timelines = new RecordedSteps();
        $projection = new ProjectTimeline($timelines);

        self::assertSame(ProjectionOutcome::Applied, $projection->project(self::news('evt_1')));
        self::assertSame(ProjectionOutcome::Duplicate, $projection->project(self::news('evt_1')));
        self::assertCount(1, $timelines->taken);
    }

    #[Test]
    public function a_step_goes_to_the_public_page_once(): void
    {
        $pages = new RecordedSteps();
        $update = new UpdateTrackingPage($pages);

        self::assertSame(ProjectionOutcome::Applied, $update->update(self::news('evt_1')));
        self::assertSame(ProjectionOutcome::Duplicate, $update->update(self::news('evt_1')));
        self::assertCount(1, $pages->taken);
    }

    #[Test]
    public function each_read_model_goes_at_its_own_pace(): void
    {
        // The page reads in a consumer group of its own: it takes the step whether or not the timeline has it.
        $timelines = new RecordedSteps();
        $timelines->alreadyHas('evt_1');
        $pages = new RecordedSteps();

        self::assertSame(ProjectionOutcome::Duplicate, new ProjectTimeline($timelines)->project(self::news('evt_1')));
        self::assertSame(ProjectionOutcome::Applied, new UpdateTrackingPage($pages)->update(self::news('evt_1')));
    }

    #[Test]
    public function the_page_is_found_by_its_code_however_the_customer_typed_it(): void
    {
        $page = TrackingView::of('TX02PWW6JFR5G00', JourneyStatus::Delivered, 'tucano-express', Place::of('Betim', 'MG'), new DateTimeImmutable('2026-09-27T15:00:00Z'), []);
        $tracking = new TrackShipment(new FixedPages(['TX02PWW6JFR5G00' => $page]));

        self::assertSame($page, $tracking->track(' tx02pww6jfr5g00 '));
        $this->expectException(TrackingCodeUnknown::class);

        $tracking->track('TX02PWW6JFR5G01');
    }

    private static function news(string $eventId): TimelineNews
    {
        return TimelineNews::of(
            '01999a30-5a6b-7c8d-9e0f-1a2b3c4d5e6f',
            '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d',
            'TX02PWW6JFR5G00',
            $eventId,
            TimelineStep::of(JourneyStatus::PickedUp, new DateTimeImmutable('2026-09-27T13:00:00Z')),
        );
    }
}
