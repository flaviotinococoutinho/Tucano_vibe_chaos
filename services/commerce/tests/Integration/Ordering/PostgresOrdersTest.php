<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Ordering\Domain\Order\StatusTransition;
use Commerce\Ordering\Domain\Order\TrackingCode;
use Commerce\Ordering\Domain\Store\StoreSlug;
use Database\Seeders\FulfillmentCenterSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Builders\OrderBuilder;
use Tests\TestCase;

#[Group('integration')]
final class PostgresOrdersTest extends TestCase
{
    use RefreshDatabase;

    private ForStoringOrders $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FulfillmentCenterSeeder::class);
        $this->orders = $this->app->make(ForStoringOrders::class);
    }

    #[Test]
    public function an_order_comes_back_as_it_was_stored(): void
    {
        $order = OrderBuilder::anOrder()
            ->withLines(OrderBuilder::line('BOOK-DDD-001', 'Domain-Driven Design', 2, 18990), OrderBuilder::line('HOME-MUG-001', 'Caneca de cerâmica', 1, 4990))
            ->place();

        $this->orders->add($order);

        self::assertEquals($order->toSnapshot(), $this->orders->get($order->id())->toSnapshot());
    }

    #[Test]
    public function an_order_comes_back_in_the_store_it_was_placed_in(): void
    {
        $order = OrderBuilder::anOrder()->in('sabia')->withLines(OrderBuilder::line('HOME-MUG-001', 'Caneca de cerâmica', 1, 4990))->place();

        $this->orders->add($order);

        self::assertSame('sabia', DB::table('orders')->where('id', $order->id()->toString())->value('store'));
        self::assertTrue($this->orders->get($order->id())->isPlacedIn(StoreSlug::of('sabia')));
    }

    #[Test]
    public function an_order_from_before_the_stores_comes_back_without_one_and_still_moves_on(): void
    {
        $order = OrderBuilder::anOrder()->place();
        $this->orders->add($order);
        // A row written before the column existed.
        DB::table('orders')->where('id', $order->id()->toString())->update(['store' => null]);

        $old = $this->orders->get($order->id());
        self::assertNull($old->toSnapshot()->store);
        $old->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        $this->orders->save($old);

        self::assertSame(['paid', null], array_values((array) DB::table('orders')->where('id', $order->id()->toString())->first(['status', 'store'])));
    }

    #[Test]
    public function a_shipped_order_comes_back_with_the_code_of_its_shipment(): void
    {
        $order = OrderBuilder::anOrder()->place();
        $this->orders->add($order);

        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        $this->orders->save($order);
        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $this->orders->save($order);

        self::assertEquals($order->toSnapshot(), $this->orders->get($order->id())->toSnapshot());
        self::assertSame('TX02PWW6JFR5G00', DB::table('orders')->where('id', $order->id()->toString())->value('tracking_code'));
    }

    #[Test]
    public function a_cancelled_order_comes_back_knowing_why(): void
    {
        $order = OrderBuilder::anOrder()->place();
        $this->orders->add($order);

        $order->cancel(CancellationReason::ReservationExpired, new DateTimeImmutable('2026-09-27T12:16:00Z'));
        $this->orders->save($order);

        self::assertEquals($order->toSnapshot(), $this->orders->get($order->id())->toSnapshot());
        self::assertSame('reservation_expired', DB::table('orders')->where('id', $order->id()->toString())->value('cancellation_reason'));
    }

    #[Test]
    public function the_first_state_starts_the_history(): void
    {
        $order = OrderBuilder::anOrder()->place();

        $this->orders->add($order);

        $history = DB::table('order_status_transitions')->where('order_id', $order->id()->toString())->get(['from_status', 'to_status']);
        self::assertEquals([(object) ['from_status' => null, 'to_status' => 'pending_payment']], $history->all());
    }

    #[Test]
    public function the_history_comes_back_oldest_first_with_the_reason_of_a_cancellation(): void
    {
        $order = OrderBuilder::anOrder()->placedAt('2026-09-27T12:00:00Z')->place();
        $this->orders->add($order);
        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00.250Z'));
        $this->orders->save($order);
        $order->cancel(CancellationReason::CustomerRequest, new DateTimeImmutable('2026-09-27T12:30:00Z'));
        $this->orders->save($order);

        self::assertEquals([
            StatusTransition::initial(OrderStatus::PendingPayment, new DateTimeImmutable('2026-09-27T12:00:00Z')),
            StatusTransition::between(OrderStatus::PendingPayment, OrderStatus::Paid, new DateTimeImmutable('2026-09-27T12:05:00.250Z')),
            StatusTransition::between(OrderStatus::Paid, OrderStatus::Cancelled, new DateTimeImmutable('2026-09-27T12:30:00Z'), 'customer_request'),
        ], $this->orders->history($order->id()));
        self::assertSame([], $this->orders->history(OrderId::generate()));
    }

    #[Test]
    public function an_unknown_order_is_not_found(): void
    {
        $this->expectException(OrderNotFound::class);

        $this->orders->get(OrderId::generate());
    }
}
