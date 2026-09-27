<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Commerce\Ordering\Domain\Error\OrderTransitionNotAllowed;
use Commerce\Ordering\Domain\Event\OrderCancelled;
use Commerce\Ordering\Domain\Event\OrderPaid;
use Commerce\Ordering\Domain\Event\OrderPlaced;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\OrderBuilder;

final class OrderLifecycleTest extends TestCase
{
    #[Test]
    public function a_new_order_waits_for_payment_and_announces_itself(): void
    {
        $order = OrderBuilder::anOrder()->place();

        self::assertSame(OrderStatus::PendingPayment, $order->status);
        $events = $order->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(OrderPlaced::class, $events[0]);
        self::assertSame('tucano.commerce.order.placed', $events[0]->eventType());
        self::assertSame(['amount' => 18990, 'currency' => 'BRL'], $events[0]->payload()['total']);
    }

    #[Test]
    public function the_paid_event_carries_what_logistics_needs_to_ship(): void
    {
        $order = OrderBuilder::anOrder()->place();
        $order->releaseEvents();

        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));

        $paid = $order->releaseEvents()[0];
        self::assertInstanceOf(OrderPaid::class, $paid);
        $payload = $paid->payload();
        self::assertSame('01310100', $payload['shippingAddress']['postalCode']);
        self::assertSame('GRU1', $payload['fulfillmentCenter']);
        self::assertSame('ana@example.com', $payload['customer']['email']);
        self::assertSame([['sku' => 'BOOK-DDD-001', 'name' => 'Domain-Driven Design', 'quantity' => 1]], $payload['lines']);
    }

    #[Test]
    public function an_order_is_never_paid_twice(): void
    {
        $order = OrderBuilder::anOrder()->place();
        $order->markAsPaid(new DateTimeImmutable());

        $this->expectException(OrderTransitionNotAllowed::class);

        $order->markAsPaid(new DateTimeImmutable());
    }

    #[Test]
    public function cancelling_a_paid_order_says_where_it_came_from(): void
    {
        $order = OrderBuilder::anOrder()->place();
        $order->markAsPaid(new DateTimeImmutable());
        $order->releaseEvents();

        $order->cancel(CancellationReason::CustomerRequest, new DateTimeImmutable());

        $cancelled = $order->releaseEvents()[0];
        self::assertInstanceOf(OrderCancelled::class, $cancelled);
        self::assertSame(['reason' => 'customer_request', 'previousStatus' => 'paid'], array_intersect_key(
            $cancelled->payload(),
            ['reason' => true, 'previousStatus' => true],
        ));
    }

    #[Test]
    public function a_delivered_order_is_final(): void
    {
        $order = $this->delivered();

        $this->expectException(OrderTransitionNotAllowed::class);

        $order->cancel(CancellationReason::CustomerRequest, new DateTimeImmutable());
    }

    #[Test]
    public function every_transition_bumps_the_version(): void
    {
        self::assertSame(4, $this->delivered()->version);
    }

    #[Test]
    public function the_reservation_expires_only_while_waiting_for_payment(): void
    {
        $order = OrderBuilder::anOrder()->placedAt('2026-09-27T12:00:00Z')->place();

        self::assertFalse($order->reservationExpiredAt(new DateTimeImmutable('2026-09-27T12:14:59Z')));
        self::assertTrue($order->reservationExpiredAt(new DateTimeImmutable('2026-09-27T12:15:00Z')));

        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:10:00Z'));
        self::assertFalse($order->reservationExpiredAt(new DateTimeImmutable('2026-09-27T13:00:00Z')));
    }

    #[Test]
    public function the_snapshot_rebuilds_the_same_order(): void
    {
        $order = $this->delivered();

        $rebuilt = Order::fromSnapshot($order->toSnapshot());

        self::assertEquals($order->toSnapshot(), $rebuilt->toSnapshot());
        self::assertSame([], $rebuilt->releaseEvents());
    }

    private function delivered(): Order
    {
        $order = OrderBuilder::anOrder()->place();
        $order->markAsPaid(new DateTimeImmutable());
        $order->markAsShipped(new DateTimeImmutable());
        $order->markAsDelivered(new DateTimeImmutable());

        return $order;
    }
}
