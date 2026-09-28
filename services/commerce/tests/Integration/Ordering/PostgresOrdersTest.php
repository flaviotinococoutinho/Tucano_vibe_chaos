<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\TrackingCode;
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
    public function the_first_state_starts_the_history(): void
    {
        $order = OrderBuilder::anOrder()->place();

        $this->orders->add($order);

        $history = DB::table('order_status_transitions')->where('order_id', $order->id()->toString())->get(['from_status', 'to_status']);
        self::assertEquals([(object) ['from_status' => null, 'to_status' => 'pending_payment']], $history->all());
    }

    #[Test]
    public function an_unknown_order_is_not_found(): void
    {
        $this->expectException(OrderNotFound::class);

        $this->orders->get(OrderId::generate());
    }
}
