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
use Commerce\Ordering\Domain\Order\TrackingCode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\OrderBuilder;
use Tucano\SharedKernel\Domain\DomainEvent;

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
    public function a_cancelled_order_remembers_why(): void
    {
        $order = OrderBuilder::anOrder()->place();
        self::assertNull($order->toSnapshot()->cancellationReason);

        $order->cancel(CancellationReason::PaymentDeclined, new DateTimeImmutable('2026-09-27T12:06:00Z'));

        self::assertSame(CancellationReason::PaymentDeclined, $order->toSnapshot()->cancellationReason);
        self::assertSame(CancellationReason::PaymentDeclined, Order::fromSnapshot($order->toSnapshot())->toSnapshot()->cancellationReason);
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
    public function the_order_learns_its_tracking_code_when_it_ships(): void
    {
        $order = OrderBuilder::anOrder()->place();
        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        self::assertNull($order->toSnapshot()->trackingCode);

        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $order->markAsDelivered(new DateTimeImmutable('2026-09-27T15:00:00Z'));

        self::assertSame('TX02PWW6JFR5G00', (string) $order->toSnapshot()->trackingCode);
    }

    #[Test]
    public function every_event_of_the_order_says_its_store(): void
    {
        $mug = OrderBuilder::line('HOME-MUG-001', 'Caneca de cerâmica', 1, 4990);
        $returned = OrderBuilder::anOrder()->in('sabia')->withLines($mug)->place();
        $returned->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        $returned->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $returned->markAsReturned(new DateTimeImmutable('2026-09-29T09:00:00Z'));
        $delivered = OrderBuilder::anOrder()->in('sabia')->withLines($mug)->paid();
        $delivered->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $delivered->markAsDelivered(new DateTimeImmutable('2026-09-27T15:00:00Z'));
        $cancelled = OrderBuilder::anOrder()->in('sabia')->withLines($mug)->place();
        $cancelled->cancel(CancellationReason::PaymentDeclined, new DateTimeImmutable('2026-09-27T12:06:00Z'));

        $events = [...$returned->releaseEvents(), ...$delivered->releaseEvents(), ...$cancelled->releaseEvents()];

        self::assertSame(
            ['placed', 'paid', 'shipped', 'returned', 'shipped', 'delivered', 'placed', 'cancelled'],
            array_map(static fn(DomainEvent $event): string => substr($event->eventType(), strlen('tucano.commerce.order.')), $events),
        );
        self::assertSame(array_fill(0, 8, 'sabia'), array_map(static fn(DomainEvent $event): mixed => $event->payload()['store'] ?? null, $events));
    }

    #[Test]
    public function an_order_from_before_the_stores_goes_on_without_one(): void
    {
        $order = OrderBuilder::anOrder()->placedBeforeTheStores();

        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));

        self::assertNull($order->toSnapshot()->store);
        self::assertArrayNotHasKey('store', $order->releaseEvents()[0]->payload(), 'the contracts take no store rather than a null one');
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
        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable());
        $order->markAsDelivered(new DateTimeImmutable());

        return $order;
    }
}
