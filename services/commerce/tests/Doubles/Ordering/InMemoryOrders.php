<?php

declare(strict_types=1);

namespace Tests\Doubles\Ordering;

use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;
use DateTimeImmutable;

final class InMemoryOrders implements ForStoringOrders
{
    /** @var array<string, Order> */
    private array $orders = [];

    public function add(Order $order): void
    {
        $this->orders[$order->id()->toString()] = $order;
    }

    public function get(OrderId $id): Order
    {
        return $this->orders[$id->toString()] ?? throw OrderNotFound::withId($id->toString());
    }

    public function save(Order $order): void
    {
        $order->releaseTransitions();
        $this->orders[$order->id()->toString()] = $order;
    }

    public function nextExpired(DateTimeImmutable $now): ?Order
    {
        foreach ($this->orders as $order) {
            if ($order->reservationExpiredAt($now)) {
                return $order;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->orders);
    }
}
