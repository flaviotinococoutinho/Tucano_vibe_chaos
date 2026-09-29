<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Commerce\Ordering\Domain\Error\OrderTransitionNotAllowed;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Ordering\Domain\Order\TrackingCode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\OrderBuilder;

final class OrderStatusTest extends TestCase
{
    /** @return iterable<string, array{OrderStatus, list<OrderStatus>}> */
    public static function transitions(): iterable
    {
        yield 'pending payment' => [OrderStatus::PendingPayment, [OrderStatus::Paid, OrderStatus::Cancelled]];
        yield 'paid' => [OrderStatus::Paid, [OrderStatus::Shipped, OrderStatus::Cancelled]];
        yield 'shipped' => [OrderStatus::Shipped, [OrderStatus::Delivered, OrderStatus::Returned]];
        yield 'delivered' => [OrderStatus::Delivered, []];
        yield 'cancelled' => [OrderStatus::Cancelled, []];
        yield 'returned' => [OrderStatus::Returned, []];
    }

    /** @param list<OrderStatus> $allowed */
    #[Test]
    #[DataProvider('transitions')]
    public function it_follows_the_documented_state_machine(OrderStatus $from, array $allowed): void
    {
        foreach (OrderStatus::cases() as $target) {
            self::assertSame(in_array($target, $allowed, true), $from->canMoveTo($target), sprintf('%s -> %s', $from->value, $target->value));
        }
        self::assertSame($allowed === [], $from->isFinal());
    }

    #[Test]
    public function every_move_adds_one_to_the_version_of_the_order(): void
    {
        foreach (OrderStatus::cases() as $from) {
            foreach ($from->next() as $to) {
                $move = sprintf('%s -> %s', $from->value, $to->value);
                self::assertSame($from->orderVersion() + 1, $to->orderVersion($from), $move);
                if ($to !== OrderStatus::Cancelled) {
                    // A single way in: the status alone says as much as the move.
                    self::assertSame($to->orderVersion($from), $to->orderVersion(), $move);
                }
            }
        }
    }

    #[Test]
    public function the_version_of_a_status_is_the_version_the_order_has_there(): void
    {
        $order = OrderBuilder::anOrder()->place();
        self::assertSame($order->version, $order->status->orderVersion());

        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        self::assertSame($order->version, $order->status->orderVersion());
        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        self::assertSame($order->version, $order->status->orderVersion());
        $order->markAsReturned(new DateTimeImmutable('2026-09-28T13:00:00Z'));
        self::assertSame($order->version, $order->status->orderVersion());

        $cancelledWhilePaid = OrderBuilder::anOrder()->paid();
        $cancelledWhilePaid->cancel(CancellationReason::CustomerRequest, new DateTimeImmutable('2026-09-27T12:30:00Z'));
        self::assertSame($cancelledWhilePaid->version, OrderStatus::Cancelled->orderVersion(OrderStatus::Paid));
    }

    #[Test]
    public function a_cancelled_order_counts_from_the_status_it_was_cancelled_in(): void
    {
        self::assertSame(2, OrderStatus::Cancelled->orderVersion(OrderStatus::PendingPayment));
        self::assertSame(3, OrderStatus::Cancelled->orderVersion(OrderStatus::Paid));

        $this->expectException(InvalidOrder::class);

        OrderStatus::Cancelled->orderVersion();
    }

    #[Test]
    public function a_move_the_state_machine_does_not_have_has_no_version(): void
    {
        $this->expectException(OrderTransitionNotAllowed::class);

        OrderStatus::Cancelled->orderVersion(OrderStatus::Shipped);
    }
}
