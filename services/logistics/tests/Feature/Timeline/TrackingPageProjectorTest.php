<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline;

use Logistics\Timeline\Adapter\Driving\Kafka\TrackingPageProjector;
use Logistics\Timeline\Application\UseCase\UpdateTrackingPage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Doubles\RecordingLogger;
use Tests\Doubles\Timeline\RecordedSteps;
use Tests\Fixtures\ShipmentEvents;
use Tests\TestCase;

final class TrackingPageProjectorTest extends TestCase
{
    #[Test]
    public function every_step_of_the_journey_reaches_the_public_page_once(): void
    {
        $pages = new RecordedSteps();
        $projector = new TrackingPageProjector(new UpdateTrackingPage($pages), new RecordingLogger());

        $projector->handle(ShipmentEvents::message(ShipmentEvents::of('created', ShipmentEvents::created())));
        $picked = ShipmentEvents::of('picked_up', eventId: '01999a31-0000-7000-8000-000000000001');
        $projector->handle(ShipmentEvents::message($picked));
        $projector->handle(ShipmentEvents::message($picked));
        $projector->handle(ShipmentEvents::message(str_replace('tucano.logistics.shipment.picked_up', 'tucano.logistics.shipment.teleported', ShipmentEvents::of('picked_up'))));

        self::assertSame(['created', 'picked_up'], array_map(static fn($news): string => $news->step->status->value, $pages->taken));
        self::assertSame('tucano-express', $pages->taken[0]->carrier);
        self::assertSame([ShipmentEvents::STORE, ShipmentEvents::STORE], [$pages->taken[0]->store, $pages->taken[1]->store], 'Every step says the store the page belongs to.');
    }

    #[Test]
    public function a_step_from_before_the_stores_reaches_a_page_of_no_store(): void
    {
        $pages = new RecordedSteps();
        $projector = new TrackingPageProjector(new UpdateTrackingPage($pages), new RecordingLogger());

        $projector->handle(ShipmentEvents::message(ShipmentEvents::of('picked_up', store: null)));

        self::assertCount(1, $pages->taken);
        self::assertNull($pages->taken[0]->store);
    }
}
