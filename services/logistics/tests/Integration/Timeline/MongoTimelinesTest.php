<?php

declare(strict_types=1);

namespace Tests\Integration\Timeline;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Logistics\Timeline\Adapter\Driven\MongoTimelines;
use Logistics\Timeline\Application\TimelineNews;
use Logistics\Timeline\Domain\JourneyStatus;
use Logistics\Timeline\Domain\Place;
use Logistics\Timeline\Domain\TimelineStep;
use MongoDB\BSON\Binary;
use MongoDB\Database;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\Fixtures\ShipmentEvents;
use Tests\TestCase;

/** The timeline against MongoDB, with the collection validator of database/mongo in force. */
#[Group('integration')]
final class MongoTimelinesTest extends TestCase
{
    private Database $database;

    private MongoTimelines $timelines;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = $this->app->make(Database::class);
        $this->database->drop();
        self::assertSame(0, Artisan::call('mongo:migrate'));
        $this->timelines = new MongoTimelines($this->database);
    }

    #[Test]
    public function the_steps_pile_up_in_one_document_and_a_redelivery_adds_nothing(): void
    {
        self::assertTrue($this->timelines->append(self::news('evt_1', TimelineStep::of(JourneyStatus::Created, new DateTimeImmutable('2026-09-27T12:00:00Z')), 'tucano-express')));
        self::assertTrue($this->timelines->append(self::news('evt_2', TimelineStep::of(JourneyStatus::InTransit, new DateTimeImmutable('2026-09-27T14:00:00Z'), 'Hub Contagem (MG)'))));
        self::assertFalse($this->timelines->append(self::news('evt_2', TimelineStep::of(JourneyStatus::InTransit, new DateTimeImmutable('2026-09-27T14:00:00Z'), 'Hub Contagem (MG)'))));

        $timeline = $this->database->selectCollection('shipment_timelines')->findOne(['_id' => new Binary(Uuid::fromString(ShipmentEvents::SHIPMENT)->getBytes(), Binary::TYPE_UUID)]);
        self::assertInstanceOf(BSONDocument::class, $timeline);
        self::assertSame(['in_transit', 'tucano-express', ShipmentEvents::TRACKING_CODE, 2], [$timeline['status'], $timeline['carrier'], $timeline['trackingCode'], (int) (string) $timeline['version']]);
        self::assertSame(['created', 'in_transit'], array_map(static fn($event): string => (string) $event['status'], iterator_to_array($timeline['events'], false)));
        self::assertSame(ShipmentEvents::STORE, $timeline['store']);
    }

    #[Test]
    public function a_timeline_that_starts_after_the_creation_still_satisfies_the_collection(): void
    {
        self::assertTrue($this->timelines->append(self::news('evt_9', TimelineStep::of(JourneyStatus::PickedUp, new DateTimeImmutable('2026-09-27T13:00:00Z')))));

        $timeline = $this->database->selectCollection('shipment_timelines')->findOne();
        self::assertInstanceOf(BSONDocument::class, $timeline);
        self::assertSame('unknown', $timeline['carrier']);
    }

    #[Test]
    public function a_timeline_from_before_the_stores_has_no_store_and_still_satisfies_the_collection(): void
    {
        self::assertTrue($this->timelines->append(self::news('evt_1', TimelineStep::of(JourneyStatus::Created, new DateTimeImmutable('2026-09-27T12:00:00Z')), 'tucano-express', store: null)));

        $timeline = $this->database->selectCollection('shipment_timelines')->findOne();
        self::assertInstanceOf(BSONDocument::class, $timeline);
        self::assertFalse(isset($timeline['store']));
    }

    private static function news(string $eventId, TimelineStep $step, ?string $carrier = null, ?string $store = ShipmentEvents::STORE): TimelineNews
    {
        return TimelineNews::of(ShipmentEvents::SHIPMENT, ShipmentEvents::ORDER, ShipmentEvents::TRACKING_CODE, $store, $eventId, $step, $carrier, $carrier === null ? null : Place::of('Betim', 'MG'));
    }
}
