<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Illuminate\Support\Facades\Context;
use Logistics\CarrierSelection\Domain\Consignment;
use Logistics\CarrierSelection\Domain\NoCarrierFits;
use Logistics\Shipping\Adapter\Driving\Kafka\OrderEventHandler;
use Logistics\Shipping\Application\OrderLine;
use Logistics\Shipping\Domain\Destination\Coordinates;
use Logistics\Shipping\Domain\Error\NoCarrierChosen;
use Logistics\Shipping\Domain\Error\ProductNotSyncedYet;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;
use Logistics\Shipping\Domain\Product\Sku;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Doubles\RecordingLogger;
use Tests\Doubles\Shipping\RecordedShipmentRequests;
use Tests\Fixtures\OrderEvents;
use Tests\TestCase;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

final class OrderEventHandlerTest extends TestCase
{
    private RecordedShipmentRequests $shipments;

    private RecordingLogger $logger;

    private OrderEventHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shipments = new RecordedShipmentRequests();
        $this->logger = new RecordingLogger();
        $this->handler = new OrderEventHandler($this->shipments, $this->shipments, $this->logger);
    }

    #[Test]
    public function a_paid_order_asks_for_a_shipment_with_what_logistics_needs(): void
    {
        $this->handler->handle(OrderEvents::message(OrderEvents::paid()));

        self::assertCount(1, $this->shipments->paid);
        $order = $this->shipments->paid[0];
        self::assertSame([OrderEvents::PAID_EVENT, OrderEvents::ORDER, 'GRU1'], [$order->eventId, $order->orderId->toString(), (string) $order->origin]);
        self::assertSame(['Ana Souza', 'ana@example.com'], [$order->recipient->name, $order->recipient->email]);
        $destination = $order->destination;
        self::assertSame(['Avenida Paulista', '1000', 'Apto 12', 'Bela Vista', 'São Paulo', 'SP', '01310100'], [
            $destination->street, $destination->number, $destination->complement, $destination->district,
            $destination->city, $destination->state->value, (string) $destination->postalCode,
        ]);
        self::assertEquals(new Coordinates(-23.561414, -46.655881), $destination->coordinates);
        self::assertSame([['BOOK-DDD-001', 2], ['HOME-MUG-001', 1]], array_map(
            static fn(OrderLine $line): array => [(string) $line->sku, $line->quantity->value],
            $order->lines,
        ));
    }

    #[Test]
    public function the_flow_keeps_the_correlation_of_the_order_and_names_its_cause(): void
    {
        $this->handler->handle(OrderEvents::message(OrderEvents::paid()));

        self::assertSame('req-42#3', Context::get('correlation_id'));
        self::assertSame(OrderEvents::PAID_EVENT, Context::get('causation_id'));
    }

    #[Test]
    public function an_address_may_come_without_complement_or_coordinates(): void
    {
        $this->handler->handle(OrderEvents::message(OrderEvents::paid(['shippingAddress' => OrderEvents::plainAddress(), 'fulfillmentCenter' => 'BHZ1'])));

        $destination = $this->shipments->paid[0]->destination;
        self::assertNull($destination->complement);
        self::assertNull($destination->coordinates);
    }

    #[Test]
    public function a_cancelled_paid_order_asks_to_stop_its_shipment(): void
    {
        $this->handler->handle(OrderEvents::message(OrderEvents::cancelled()));

        self::assertCount(1, $this->shipments->cancelled);
        self::assertSame([OrderEvents::CANCELLED_EVENT, OrderEvents::ORDER], [$this->shipments->cancelled[0]->eventId, $this->shipments->cancelled[0]->orderId->toString()]);
    }

    #[Test]
    public function an_order_cancelled_before_payment_never_had_a_shipment(): void
    {
        $this->handler->handle(OrderEvents::message(OrderEvents::expired()));

        self::assertSame([], $this->shipments->cancelled);
    }

    #[Test]
    public function the_other_order_events_are_not_for_logistics(): void
    {
        $this->handler->handle(OrderEvents::message(OrderEvents::shipped()));

        self::assertSame([[], []], [$this->shipments->paid, $this->shipments->cancelled]);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        yield 'not json' => ['{"specversion":'];
        yield 'not a CloudEvent' => ['{"orderId": "01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d"}'];
        yield 'an order id that is not a UUIDv7' => [OrderEvents::paid(['orderId' => 'order-1'])];
        yield 'no customer' => [OrderEvents::paid(['customer' => null])];
        yield 'a state that does not exist' => [OrderEvents::paid(['shippingAddress' => ['street' => 'Rua A', 'number' => '1', 'district' => 'Centro', 'city' => 'Lugar', 'state' => 'XX', 'postalCode' => '01310100']])];
        yield 'a quantity as text' => [OrderEvents::paid(['lines' => [['sku' => 'BOOK-DDD-001', 'name' => 'Domain-Driven Design', 'quantity' => '2']]])];
        yield 'a center outside the format' => [OrderEvents::paid(['fulfillmentCenter' => 'gru1'])];
        yield 'a cancellation without the previous status' => [OrderEvents::cancelled(['previousStatus' => null])];
    }

    #[Test]
    #[DataProvider('unreadable')]
    public function an_unreadable_event_goes_straight_to_the_dead_letters(string $payload): void
    {
        try {
            $this->handler->handle(OrderEvents::message($payload));
            self::fail('An unreadable event should be a permanent failure.');
        } catch (PermanentFailure $failure) {
            self::assertStringStartsWith('Unreadable event at commerce.orders.v1[2]@7: ', $failure->getMessage());
        }

        self::assertSame([[], []], [$this->shipments->paid, $this->shipments->cancelled]);
    }

    #[Test]
    public function a_product_the_catalog_copy_has_not_seen_yet_is_retried(): void
    {
        $this->shipments->refuseWith(ProductNotSyncedYet::sku(Sku::of('BOOK-DDD-001')));

        try {
            $this->handler->handle(OrderEvents::message(OrderEvents::paid()));
            self::fail('The failure should go back to the consumer, which retries it.');
        } catch (ProductNotSyncedYet) {
            self::assertSame([], $this->logger->messagesAt('warning'));
        }
    }

    #[Test]
    public function a_shipment_that_left_the_warehouse_goes_to_a_person_with_a_warning(): void
    {
        $refusal = TransitionNotAllowed::for(new TrackingCode(Snowflake::fromInt(97663548934766595)), ShipmentStatus::PickedUp, ShipmentStatus::Cancelled);
        $this->shipments->refuseWith($refusal);

        try {
            $this->handler->handle(OrderEvents::message(OrderEvents::cancelled()));
            self::fail('A refusal of the domain should be a permanent failure.');
        } catch (PermanentFailure $failure) {
            self::assertSame($refusal, $failure->getPrevious());
            self::assertStringContainsString('TX02PQRFBTW5G03 is picked_up and cannot move to cancelled', $failure->getMessage());
        }

        self::assertSame(['{type} of order {orderId} was refused and needs a person: {reason}'], $this->logger->messagesAt('warning'));
    }

    #[Test]
    public function a_paid_order_no_carrier_takes_goes_to_a_person_too(): void
    {
        $this->shipments->refuseWith(NoCarrierChosen::because(NoCarrierFits::for(new Consignment('SP', 'SP', 1_000_001))));

        $this->expectException(PermanentFailure::class);

        $this->handler->handle(OrderEvents::message(OrderEvents::paid()));
    }
}
