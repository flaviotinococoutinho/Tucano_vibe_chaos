<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Logistics\Shipping\Adapter\Driven\CarrierFakeTracking;
use Logistics\Shipping\Application\CarrierEvent;
use Logistics\Shipping\Application\CarrierStep;
use Logistics\Shipping\Domain\Error\CarrierUnreachable;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\Doubles\RecordingLogger;
use Tests\TestCase;
use Throwable;

final class CarrierFakeTrackingTest extends TestCase
{
    private const string SHIPMENT = '01999a30-5a6b-7c8d-9e0f-1a2b3c4d5e6f';

    /** @var list<RequestInterface> */
    private array $sent = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new RecordingLogger();
    }

    #[Test]
    public function the_pickup_is_found_by_the_shipment_id_and_its_history_comes_in_order(): void
    {
        $history = $this->tracking(
            new Response(200, [], (string) json_encode(['data' => [['id' => 'pk_01ABC', 'status' => 'delivered']]])),
            new Response(200, [], (string) json_encode(['data' => [self::event('evt_1', 'parcel.picked_up'), self::event('evt_2', 'parcel.out_for_delivery', ['attempt' => 1])]])),
        )->eventsOf(ShipmentId::fromString(self::SHIPMENT));

        self::assertSame(
            [['evt_1', CarrierStep::PickedUp], ['evt_2', CarrierStep::OutForDelivery]],
            array_map(static fn(CarrierEvent $event): array => [$event->report->eventId, $event->step], $history),
        );
        self::assertSame(['/carriers/v1/pickups', 'reference=' . self::SHIPMENT], [$this->sent[0]->getUri()->getPath(), $this->sent[0]->getUri()->getQuery()]);
        self::assertSame('/carriers/v1/pickups/pk_01ABC/events', $this->sent[1]->getUri()->getPath());
    }

    #[Test]
    public function no_pickup_for_the_shipment_is_an_empty_history(): void
    {
        self::assertSame([], $this->tracking(new Response(200, [], '{"data":[]}'))->eventsOf(ShipmentId::fromString(self::SHIPMENT)));
        self::assertSame([], $this->tracking(new Response(200, [], '{"data":[{"id":"pk_01ABC"}]}'), new Response(404))->eventsOf(ShipmentId::fromString(self::SHIPMENT)));
    }

    #[Test]
    public function an_event_that_cannot_be_read_is_skipped_and_logged(): void
    {
        $history = $this->tracking(
            new Response(200, [], '{"data":[{"id":"pk_01ABC"}]}'),
            new Response(200, [], (string) json_encode(['data' => [self::event('evt_1', 'parcel.hub_scanned'), self::event('evt_2', 'parcel.returning')]])),
        )->eventsOf(ShipmentId::fromString(self::SHIPMENT));

        self::assertSame([CarrierStep::Returning], array_map(static fn(CarrierEvent $event): CarrierStep => $event->step, $history));
        self::assertSame(['warning'], array_column($this->logger->records, 'level'));
    }

    #[Test]
    public function a_carrier_that_fails_or_does_not_answer_is_unreachable(): void
    {
        foreach ([new Response(503), new Response(200, [], 'not json'), new ConnectException('Connection refused', new Request('GET', '/'))] as $answer) {
            try {
                $this->tracking($answer)->eventsOf(ShipmentId::fromString(self::SHIPMENT));
                self::fail('The carrier should be unreachable.');
            } catch (CarrierUnreachable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function tracking(Response|Throwable ...$answers): CarrierFakeTracking
    {
        $stack = HandlerStack::create(new MockHandler(array_values($answers)));
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->sent[] = $request;

            return $request;
        }));

        return new CarrierFakeTracking(new Client(['handler' => $stack, 'base_uri' => 'http://carriers.test']), 2_000, $this->logger);
    }

    /**
     * @param array<string, int|string> $details
     *
     * @return array<string, mixed>
     */
    private static function event(string $id, string $type, array $details = []): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'createdAt' => '2026-09-27T15:00:00.000Z',
            'data' => ['pickupId' => 'pk_01ABC', 'carrier' => 'correio-nacional', 'reference' => self::SHIPMENT, 'trackingCode' => 'TX02PWW6JFR5G00', ...$details],
        ];
    }
}
