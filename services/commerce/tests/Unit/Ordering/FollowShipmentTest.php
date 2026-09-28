<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Commerce\Ordering\Application\FollowOutcome;
use Commerce\Ordering\Application\ShipmentNews;
use Commerce\Ordering\Application\UseCase\FollowShipment;
use Commerce\Ordering\Domain\Error\OrderTransitionNotAllowed;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Ordering\Domain\Order\TrackingCode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\OrderBuilder;
use Tests\Doubles\Ordering\InMemoryOrders;
use Tests\Doubles\Ordering\RecordedRefunds;
use Tests\Doubles\Shared\DirectTransactions;
use Tests\Doubles\Shared\InMemoryInbox;
use Tests\Doubles\Shared\RecordedEvents;

final class FollowShipmentTest extends TestCase
{
    private InMemoryOrders $orders;

    private RecordedEvents $events;

    private RecordedRefunds $refunds;

    private FollowShipment $follow;

    private Order $order;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrders();
        $this->events = new RecordedEvents();
        $this->refunds = new RecordedRefunds();
        $this->follow = new FollowShipment(new DirectTransactions(), new InMemoryInbox(), $this->orders, $this->refunds, $this->events);
        $this->order = OrderBuilder::anOrder()->paid();
        $this->orders->add($this->order);
    }

    #[Test]
    public function a_paid_order_follows_its_shipment_to_the_door(): void
    {
        self::assertSame(FollowOutcome::Applied, $this->follow->recordShipped($this->news('evt_1', '13:00')));
        self::assertSame(FollowOutcome::Applied, $this->follow->recordDelivered($this->news('evt_2', '15:00')));

        self::assertSame(OrderStatus::Delivered, $this->order->toSnapshot()->status);
        self::assertSame('TX02PWW6JFR5G00', (string) $this->order->toSnapshot()->trackingCode);
        self::assertSame(['tucano.commerce.order.shipped', 'tucano.commerce.order.delivered'], $this->events->types());
        self::assertSame([], $this->refunds->orders);
    }

    #[Test]
    public function an_order_that_comes_back_asks_for_its_money_back(): void
    {
        $this->follow->recordShipped($this->news('evt_1', '13:00'));

        $this->follow->recordReturned($this->news('evt_2', '18:00'));

        self::assertSame(OrderStatus::Returned, $this->order->toSnapshot()->status);
        self::assertSame([$this->order->id()->toString()], $this->refunds->orders);
        self::assertSame(['tucano.commerce.order.shipped', 'tucano.commerce.order.returned'], $this->events->types());
    }

    #[Test]
    public function the_same_event_twice_moves_the_order_once(): void
    {
        self::assertSame(FollowOutcome::Applied, $this->follow->recordShipped($this->news('evt_1', '13:00')));
        self::assertSame(FollowOutcome::Duplicate, $this->follow->recordShipped($this->news('evt_1', '13:00')));

        self::assertCount(1, $this->events->events);
    }

    #[Test]
    public function a_step_the_order_cannot_take_is_refused(): void
    {
        $this->expectException(OrderTransitionNotAllowed::class);

        $this->follow->recordDelivered($this->news('evt_1', '15:00'));
    }

    private function news(string $eventId, string $time): ShipmentNews
    {
        return ShipmentNews::of($eventId, $this->order->id(), TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T' . $time . ':00Z'));
    }
}
