<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Logistics\Shipping\Adapter\Driving\Kafka\PickupBookingHandler;
use Logistics\Shipping\Application\BookingOutcome;
use Logistics\Shipping\Application\Port\Driving\ForBookingPickups;
use Logistics\Shipping\Domain\Error\PickupNotBooked;
use Logistics\Shipping\Domain\Error\PickupRefused;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\Doubles\RecordingLogger;
use Tests\TestCase;
use Throwable;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;

final class PickupBookingHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $booked = [];

    private ?Throwable $failure = null;

    private RecordingLogger $logger;

    private PickupBookingHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new RecordingLogger();
        $this->handler = new PickupBookingHandler(new readonly class ($this) implements ForBookingPickups {
            public function __construct(private PickupBookingHandlerTest $test) {}

            public function book(ShipmentId $shipment): BookingOutcome
            {
                return $this->test->book($shipment);
            }
        }, $this->logger);
    }

    public function book(ShipmentId $shipment): BookingOutcome
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->booked[] = $shipment->toString();

        return BookingOutcome::Booked;
    }

    #[Test]
    public function a_shipment_ready_for_pickup_books_its_carrier(): void
    {
        $shipmentId = Uuid::uuid7()->toString();

        $this->handler->handle(self::message('tucano.logistics.shipment.ready_for_pickup', $shipmentId));

        self::assertSame([$shipmentId], $this->booked);
    }

    #[Test]
    public function the_other_shipment_events_are_not_for_the_carrier(): void
    {
        $this->handler->handle(self::message('tucano.logistics.shipment.created', Uuid::uuid7()->toString()));

        self::assertSame([], $this->booked);
    }

    #[Test]
    public function a_carrier_that_does_not_answer_is_retried(): void
    {
        $this->failure = PickupNotBooked::because('Connection refused');

        $this->expectException(PickupNotBooked::class);

        $this->handler->handle(self::message('tucano.logistics.shipment.ready_for_pickup', Uuid::uuid7()->toString()));
    }

    #[Test]
    public function a_refusal_goes_to_the_dead_letter_topic_and_to_a_person(): void
    {
        $this->failure = PickupRefused::because('the carrier does not serve this state');

        try {
            $this->handler->handle(self::message('tucano.logistics.shipment.ready_for_pickup', Uuid::uuid7()->toString()));
            self::fail('A refusal is permanent.');
        } catch (PermanentFailure) {
            self::assertSame(['Pickup of shipment {shipmentId} was refused and needs a person: {reason}'], $this->logger->messagesAt('warning'));
        }
    }

    private static function message(string $type, string $shipmentId): ReceivedMessage
    {
        $event = [
            'specversion' => '1.0',
            'id' => Uuid::uuid7()->toString(),
            'source' => '/logistics',
            'type' => $type,
            'subject' => $shipmentId,
            'time' => '2026-09-27T15:00:00.000Z',
            'datacontenttype' => 'application/json',
            'correlationid' => 'req-42#3',
            'data' => ['shipmentId' => $shipmentId, 'trackingCode' => 'TX02PRCV4T05G00', 'orderId' => Uuid::uuid7()->toString()],
        ];

        return new ReceivedMessage('logistics.shipments.v1', 1, 7, $shipmentId, (string) json_encode($event));
    }
}
