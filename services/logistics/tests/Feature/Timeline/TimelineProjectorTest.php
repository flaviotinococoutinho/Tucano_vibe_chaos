<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline;

use Logistics\Timeline\Adapter\Driving\Kafka\TimelineProjector;
use Logistics\Timeline\Application\ProjectionOutcome;
use Logistics\Timeline\Application\UseCase\ProjectTimeline;
use Logistics\Timeline\Domain\JourneyStatus;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\AssertsContracts;
use Tests\Doubles\RecordingLogger;
use Tests\Doubles\Timeline\RecordedSteps;
use Tests\Fixtures\ShipmentEvents;
use Tests\TestCase;
use Tucano\Messaging\Kafka\PermanentFailure;

final class TimelineProjectorTest extends TestCase
{
    use AssertsContracts;

    private RecordedSteps $timelines;

    private TimelineProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timelines = new RecordedSteps();
        $this->projector = new TimelineProjector(new ProjectTimeline($this->timelines), new RecordingLogger());
    }

    #[Test]
    public function every_event_of_the_journey_is_a_step_with_the_detail_that_helps(): void
    {
        foreach ([
            ['created', ShipmentEvents::created()],
            ['in_transit', ['hub' => 'Hub Contagem (MG)']],
            ['out_for_delivery', ['attempt' => 1]],
            ['delivery_failed', ['attempt' => 1, 'reason' => 'recipient_absent']],
            ['delivered', ['attempt' => 2]],
        ] as [$step, $details]) {
            $this->projector->handle(ShipmentEvents::message(ShipmentEvents::of($step, $details)));
        }

        $taken = $this->timelines->taken;
        self::assertSame(['created', 'in_transit', 'out_for_delivery', 'delivery_failed', 'delivered'], array_map(static fn($news): string => $news->step->status->value, $taken));
        self::assertSame(['tucano-express', 'Betim', 'MG'], [$taken[0]->carrier, $taken[0]->destination?->municipality, $taken[0]->destination?->state]);
        self::assertSame('Hub Contagem (MG)', $taken[1]->step->hub);
        self::assertSame([1, 'recipient_absent'], [$taken[3]->step->attempt, $taken[3]->step->reason]);
        self::assertNull($taken[4]->carrier, 'Only shipment.created carries the carrier.');
    }

    #[Test]
    public function the_same_event_twice_is_one_step(): void
    {
        $picked = ShipmentEvents::of('picked_up', eventId: '01999a31-0000-7000-8000-000000000001');

        $this->projector->handle(ShipmentEvents::message($picked));
        $this->projector->handle(ShipmentEvents::message($picked));

        self::assertCount(1, $this->timelines->taken);
        self::assertSame(JourneyStatus::PickedUp, $this->timelines->taken[0]->step->status);
    }

    #[Test]
    public function an_event_that_is_not_a_shipment_step_is_left_alone(): void
    {
        $this->projector->handle(ShipmentEvents::message(str_replace('tucano.logistics.shipment.picked_up', 'tucano.logistics.shipment.teleported', ShipmentEvents::of('picked_up'))));

        self::assertSame([], $this->timelines->taken);
    }

    #[Test]
    public function a_created_event_without_its_destination_goes_to_the_dead_letters(): void
    {
        $this->expectException(PermanentFailure::class);

        $this->projector->handle(ShipmentEvents::message(ShipmentEvents::of('created', ['carrier' => 'tucano-express'])));
    }

    #[Test]
    public function the_fixtures_speak_the_published_language_of_logistics(): void
    {
        foreach (['created' => ShipmentEvents::created(), 'picked_up' => [], 'delivery_failed' => ['attempt' => 1, 'reason' => 'recipient_absent']] as $step => $details) {
            $event = json_decode(ShipmentEvents::of($step, $details), flags: JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $event);
            self::assertMatchesContract('logistics.shipment.' . $step . '.schema.json', $event->data);
        }
        self::assertSame(ProjectionOutcome::Applied, ProjectionOutcome::from('applied'));
    }
}
