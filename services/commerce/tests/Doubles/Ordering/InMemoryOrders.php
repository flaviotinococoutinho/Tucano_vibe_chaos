<?php

declare(strict_types=1);

namespace Tests\Doubles\Ordering;

use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\StatusTransition;
use DateTimeImmutable;

final class InMemoryOrders implements ForStoringOrders
{
    /** @var array<string, Order> */
    private array $orders = [];

    /** @var array<string, list<StatusTransition>> */
    private array $histories = [];

    public function add(Order $order): void
    {
        $this->orders[$order->id()->toString()] = $order;
        $this->histories[$order->id()->toString()] = $order->releaseTransitions();
    }

    public function get(OrderId $id): Order
    {
        return $this->orders[$id->toString()] ?? throw OrderNotFound::withId($id->toString());
    }

    public function history(OrderId $id): array
    {
        return $this->histories[$id->toString()] ?? [];
    }

    public function lock(OrderId $id): Order
    {
        return $this->get($id);
    }

    public function save(Order $order): void
    {
        $id = $order->id()->toString();
        $this->histories[$id] = [...$this->histories[$id] ?? [], ...$order->releaseTransitions()];
        $this->orders[$id] = $order;
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
