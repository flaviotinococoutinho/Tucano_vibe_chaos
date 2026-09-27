<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Port\Driven\ForPublishingEvents;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\Builders\ShipmentBuilder;
use Tests\Fixtures\OrderEvents;
use Tests\TestCase;

#[Group('integration')]
final class OutboxEventsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_event_goes_to_the_topic_of_its_aggregate_with_the_ids_of_its_flow(): void
    {
        Context::add('correlation_id', 'req-7#1');
        Context::add('causation_id', OrderEvents::PAID_EVENT);
        $event = ShipmentBuilder::aShipment()->create()->releaseEvents()[0];

        $this->app->make(ForPublishingEvents::class)->publish($event);

        $message = DB::table('outbox_messages')->sole();
        self::assertSame(
            [$event->eventId(), 'logistics.shipments.v1', $event->aggregateId(), 'tucano.logistics.shipment.created'],
            [$message->id, $message->topic, $message->message_key, $message->event_type],
        );
        $envelope = self::decode((string) $message->payload);
        self::assertSame(['/logistics', 'req-7#1', OrderEvents::PAID_EVENT], [$envelope->source, $envelope->correlationid, $envelope->causationid]);
        self::assertSame(['correlation_id' => 'req-7#1'], json_decode((string) $message->headers, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_flow_no_request_or_message_started_gets_its_own_correlation(): void
    {
        $this->app->make(ForPublishingEvents::class)->publish(ShipmentBuilder::aShipment()->create()->releaseEvents()[0]);

        $envelope = self::decode((string) DB::table('outbox_messages')->value('payload'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $envelope->correlationid);
        self::assertObjectNotHasProperty('causationid', $envelope);
    }

    private static function decode(string $payload): stdClass
    {
        $envelope = json_decode($payload, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $envelope);

        return $envelope;
    }
}
