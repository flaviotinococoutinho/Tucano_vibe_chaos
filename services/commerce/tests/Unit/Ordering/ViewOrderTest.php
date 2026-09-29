<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Commerce\Ordering\Application\UseCase\ViewOrder;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Store\StoreSlug;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\OrderBuilder;
use Tests\Doubles\Ordering\InMemoryOrders;
use Tests\Doubles\Shared\DirectTransactions;

final class ViewOrderTest extends TestCase
{
    private InMemoryOrders $orders;

    private ViewOrder $view;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrders();
        $this->view = new ViewOrder(new DirectTransactions(), $this->orders);
    }

    #[Test]
    public function the_order_tells_its_story_oldest_first(): void
    {
        $ana = CustomerId::generate();
        $order = OrderBuilder::anOrder()->in('arara')->by($ana)->placedAt('2026-09-27T12:00:00Z')->place();
        $this->orders->add($order);
        $order->cancel(CancellationReason::PaymentDeclined, new DateTimeImmutable('2026-09-27T12:06:00.250Z'));
        $this->orders->save($order);

        $details = $this->view->viewCustomerOrder(StoreSlug::of('arara'), $ana, $order->id())->toArray();

        self::assertSame('cancelled', $details['status']);
        self::assertSame('arara', $details['store']);
        self::assertSame([
            ['status' => 'pending_payment', 'at' => '2026-09-27T12:00:00.000+00:00', 'reason' => null],
            ['status' => 'cancelled', 'at' => '2026-09-27T12:06:00.250+00:00', 'reason' => 'payment_declined'],
        ], $details['history']);
        self::assertSame($details, $this->view->viewOrder($order->id())->toArray(), 'the order of a customer is the order');
    }

    #[Test]
    public function another_customers_order_is_not_found_like_an_order_that_does_not_exist(): void
    {
        $order = OrderBuilder::anOrder()->in('arara')->by(CustomerId::generate())->place();
        $this->orders->add($order);

        try {
            $this->view->viewCustomerOrder(StoreSlug::of('arara'), CustomerId::generate(), $order->id());
            self::fail('A customer saw an order it did not place.');
        } catch (OrderNotFound $refused) {
            self::assertSame(OrderNotFound::withId($order->id()->toString())->getMessage(), $refused->getMessage());
        }
    }

    #[Test]
    public function the_order_of_another_store_is_not_found_like_an_order_that_does_not_exist(): void
    {
        $ana = CustomerId::generate();
        $order = OrderBuilder::anOrder()->in('arara')->by($ana)->place();
        $this->orders->add($order);

        try {
            $this->view->viewCustomerOrder(StoreSlug::of('sabia'), $ana, $order->id());
            self::fail('A store showed the order of another store.');
        } catch (OrderNotFound $refused) {
            self::assertSame(OrderNotFound::withId($order->id()->toString())->getMessage(), $refused->getMessage());
        }
    }

    #[Test]
    public function an_order_from_before_the_stores_is_in_no_store(): void
    {
        $ana = CustomerId::generate();
        $order = OrderBuilder::anOrder()->by($ana)->placedBeforeTheStores();
        $this->orders->add($order);

        self::assertNull($this->view->viewOrder($order->id())->toArray()['store'], 'the order itself is still there, without a store');
        $this->expectException(OrderNotFound::class);

        $this->view->viewCustomerOrder(StoreSlug::of('arara'), $ana, $order->id());
    }

    #[Test]
    public function an_order_that_does_not_exist_is_not_found(): void
    {
        $this->expectException(OrderNotFound::class);

        $this->view->viewCustomerOrder(StoreSlug::of('sabia'), CustomerId::generate(), OrderId::generate());
    }
}
