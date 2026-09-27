<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Commerce\Ordering\Application\UseCase\ExpireOrders;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\OrderBuilder;
use Tests\Doubles\Ordering\FakeStockReservations;
use Tests\Doubles\Ordering\InMemoryOrders;
use Tests\Doubles\Shared\DirectTransactions;
use Tests\Doubles\Shared\RecordedEvents;
use Tucano\SharedKernel\Time\FrozenClock;

final class ExpireOrdersTest extends TestCase
{
    private InMemoryOrders $orders;

    private FakeStockReservations $stock;

    private RecordedEvents $events;

    private FrozenClock $clock;

    private ExpireOrders $expireOrders;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrders();
        $this->stock = new FakeStockReservations('GRU1');
        $this->events = new RecordedEvents();
        $this->clock = new FrozenClock('2026-09-27T12:00:00Z');
        $this->expireOrders = new ExpireOrders(new DirectTransactions(), $this->orders, $this->stock, $this->events, $this->clock);
    }

    #[Test]
    public function an_order_past_its_window_is_cancelled_and_gives_its_stock_back(): void
    {
        $order = $this->placedAtNoon();
        $this->clock->moveTo('2026-09-27T12:15:00Z');

        self::assertEquals($order->id(), $this->expireOrders->expireNext());
        self::assertSame(OrderStatus::Cancelled, $this->orders->get($order->id())->status);
        self::assertSame([$order->id()->toString()], $this->stock->releasedFor);
        self::assertSame(['tucano.commerce.order.cancelled'], $this->events->types());
    }

    #[Test]
    public function an_order_still_in_its_window_waits(): void
    {
        $this->placedAtNoon();
        $this->clock->moveTo('2026-09-27T12:14:59Z');

        self::assertNull($this->expireOrders->expireNext());
        self::assertSame([], $this->stock->releasedFor);
    }

    private function placedAtNoon(): Order
    {
        // The builder gives every order the usual 15 minutes to pay.
        $order = OrderBuilder::anOrder()->placedAt('2026-09-27T12:00:00Z')->place();
        $order->releaseEvents();
        $this->orders->add($order);

        return $order;
    }
}
