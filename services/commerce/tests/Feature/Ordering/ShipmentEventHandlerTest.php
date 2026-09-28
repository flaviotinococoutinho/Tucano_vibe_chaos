<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use Commerce\Ordering\Adapter\Driving\Kafka\ShipmentEventHandler;
use Commerce\Ordering\Application\ShipmentNews;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\AssertsContracts;
use Tests\Builders\ShipmentEvents;
use Tests\Doubles\Ordering\RecordedShipmentFollowing;
use Tests\Doubles\RecordingLogger;
use Tests\TestCase;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;

final class ShipmentEventHandlerTest extends TestCase
{
    use AssertsContracts;

    private const string ORDER = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    private RecordedShipmentFollowing $orders;

    private RecordingLogger $logger;

    private ShipmentEventHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new RecordedShipmentFollowing();
        $this->logger = new RecordingLogger();
        $this->handler = new ShipmentEventHandler($this->orders, $this->logger);
    }

    #[Test]
    public function a_pickup_a_delivery_and_a_return_move_the_order(): void
    {
        $this->handler->handle(self::message(ShipmentEvents::of('picked_up', self::ORDER, '2026-09-27T13:00:00.000Z', '01999a31-0000-7000-8000-000000000001')));
        $this->handler->handle(self::message(ShipmentEvents::of('delivered', self::ORDER, details: ['attempt' => 1])));
        $this->handler->handle(self::message(ShipmentEvents::of('returned', self::ORDER)));

        self::assertSame(['shipped', 'delivered', 'returned'], array_column($this->orders->calls, 0));
        $news = $this->orders->calls[0][1];
        self::assertInstanceOf(ShipmentNews::class, $news);
        self::assertSame(['01999a31-0000-7000-8000-000000000001', self::ORDER, '2026-09-27T13:00:00+00:00'], [$news->eventId, $news->orderId->toString(), $news->at->format(DATE_ATOM)]);
    }

    /** @return iterable<string, array{string}> */
    public static function stepsTheOrderIgnores(): iterable
    {
        foreach (['created', 'ready_for_pickup', 'in_transit', 'out_for_delivery', 'delivery_failed', 'returning', 'cancelled'] as $step) {
            yield $step => [$step];
        }
    }

    #[Test]
    #[DataProvider('stepsTheOrderIgnores')]
    public function the_other_steps_of_the_journey_are_not_for_the_order(string $step): void
    {
        $this->handler->handle(self::message(ShipmentEvents::of($step, self::ORDER)));

        self::assertSame([], $this->orders->calls);
    }

    #[Test]
    public function an_event_without_a_readable_order_goes_to_the_dead_letters(): void
    {
        $this->expectException(PermanentFailure::class);

        $this->handler->handle(self::message(ShipmentEvents::of('picked_up', 'order-1')));
    }

    #[Test]
    public function a_step_the_order_refuses_goes_to_the_dead_letters_with_a_warning(): void
    {
        $this->orders->refuseWith(OrderNotFound::withId(self::ORDER));

        try {
            $this->handler->handle(self::message(ShipmentEvents::of('picked_up', self::ORDER)));
            self::fail('A refusal should be a permanent failure.');
        } catch (PermanentFailure) {
            self::assertSame(['warning'], array_values(array_unique(array_column($this->logger->records, 'level'))));
        }
    }

    #[Test]
    public function the_fixtures_speak_the_published_language_of_logistics(): void
    {
        foreach (['picked_up' => [], 'delivered' => ['attempt' => 1], 'returned' => []] as $step => $details) {
            $event = json_decode(ShipmentEvents::of($step, self::ORDER, details: $details), flags: JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $event);
            self::assertMatchesContract('cloudevent.schema.json', $event);
            self::assertMatchesContract('logistics.shipment.' . $step . '.schema.json', $event->data);
        }
    }

    private static function message(string $payload): ReceivedMessage
    {
        return new ReceivedMessage('logistics.shipments.v2', 0, 7, ShipmentEvents::SHIPMENT, $payload);
    }
}
