<?php

declare(strict_types=1);

namespace Tests\Unit\Timeline;

use Closure;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $page = self::page('TX02PWW6JFR5G00', 'sabia');
        $tracking = new TrackShipment(new FixedPages(['TX02PWW6JFR5G00' => $page]));

        self::assertSame($page, $tracking->track(' tx02pww6jfr5g00 '));
        $this->expectException(TrackingCodeUnknown::class);

        $tracking->track('TX02PWW6JFR5G01');
    }

    #[Test]
    public function platform_wide_the_page_of_any_store_says_whose_it_is(): void
    {
        $tracking = new TrackShipment(new FixedPages([
            'TX02PWW6JFR5G00' => self::page('TX02PWW6JFR5G00', 'sabia'),
            'TX02PWW6JFR5G01' => self::page('TX02PWW6JFR5G01', null),
        ]));

        self::assertSame('sabia', $tracking->track('TX02PWW6JFR5G00')->store);
        self::assertNull($tracking->track('TX02PWW6JFR5G01')->store, 'A shipment from before the stores.');
    }

    #[Test]
    public function a_store_finds_its_own_page_however_the_customer_typed_the_code(): void
    {
        $page = self::page('TX02PWW6JFR5G00', 'sabia');

        self::assertSame($page, new TrackShipment(new FixedPages(['TX02PWW6JFR5G00' => $page]))->trackInStore('sabia', ' tx02pww6jfr5g00 '));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function pagesOutsideTheStore(): iterable
    {
        yield 'the page of another store' => ['arara', 'sabia'];
        yield 'a page from before the stores' => [null, 'sabia'];
        yield 'a store only by its name' => ['sabia', 'Sabiá'];
    }

    #[Test]
    #[DataProvider('pagesOutsideTheStore')]
    public function a_page_outside_the_store_is_the_same_refusal_as_a_code_nobody_knows(?string $storeOfThePage, string $askedBy): void
    {
        $tracking = new TrackShipment(new FixedPages(['TX02PWW6JFR5G00' => self::page('TX02PWW6JFR5G00', $storeOfThePage)]));
        $unknown = self::refusalOf(static fn() => new TrackShipment(new FixedPages([]))->trackInStore($askedBy, 'TX02PWW6JFR5G00'));

        $refusal = self::refusalOf(static fn() => $tracking->trackInStore($askedBy, 'TX02PWW6JFR5G00'));

        self::assertSame([$unknown->category(), $unknown->getMessage()], [$refusal->category(), $refusal->getMessage()]);
    }

    /** @param Closure(): mixed $tracking */
    private static function refusalOf(Closure $tracking): TrackingCodeUnknown
    {
        try {
            $tracking();
        } catch (TrackingCodeUnknown $refusal) {
            return $refusal;
        }

        self::fail('The page should not be found.');
    }

    private static function page(string $trackingCode, ?string $store): TrackingView
    {
        return TrackingView::of($trackingCode, $store, JourneyStatus::Delivered, 'tucano-express', Place::of('Betim', 'MG'), new DateTimeImmutable('2026-09-27T15:00:00Z'), []);
    }

    private static function news(string $eventId): TimelineNews
    {
        return TimelineNews::of(
            '01999a30-5a6b-7c8d-9e0f-1a2b3c4d5e6f',
            '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d',
            'TX02PWW6JFR5G00',
            'sabia',
            $eventId,
            TimelineStep::of(JourneyStatus::PickedUp, new DateTimeImmutable('2026-09-27T13:00:00Z')),
        );
    }
}
