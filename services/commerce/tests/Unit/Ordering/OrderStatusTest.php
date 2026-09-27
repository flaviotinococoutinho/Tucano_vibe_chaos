<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Commerce\Ordering\Domain\Order\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
