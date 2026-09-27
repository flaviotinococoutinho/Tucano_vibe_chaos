<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Ordering\Domain\Order\StatusTransition;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\OrderBuilder;

final class OrderTransitionsTest extends TestCase
{
    #[Test]
    public function placing_starts_the_history(): void
    {
        $order = OrderBuilder::anOrder()->placedAt('2026-09-27T12:00:00Z')->place();

        self::assertEquals(
            [new StatusTransition(null, OrderStatus::PendingPayment, new DateTimeImmutable('2026-09-27T12:00:00Z'))],
            $order->releaseTransitions(),
        );
    }

    #[Test]
    public function a_cancellation_keeps_its_reason(): void
    {
        $order = OrderBuilder::anOrder()->place();
        $order->releaseTransitions();

        $order->cancel(CancellationReason::ReservationExpired, new DateTimeImmutable('2026-09-27T12:16:00Z'));

        self::assertEquals(
            [new StatusTransition(OrderStatus::PendingPayment, OrderStatus::Cancelled, new DateTimeImmutable('2026-09-27T12:16:00Z'), 'reservation_expired')],
            $order->releaseTransitions(),
        );
    }

    #[Test]
    public function transitions_are_handed_over_once(): void
    {
        $order = OrderBuilder::anOrder()->place();
        $order->releaseTransitions();

        self::assertSame([], $order->releaseTransitions());
    }
}
