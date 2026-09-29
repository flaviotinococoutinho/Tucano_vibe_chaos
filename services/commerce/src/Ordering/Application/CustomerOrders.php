<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

/** One page of a customer's orders, newest first, and how many orders the customer has in all. */
final readonly class CustomerOrders
{
    /** @param list<OrderSummary> $orders */
    private function __construct(public Page $page, public int $total, public array $orders) {}

    /** @param list<OrderSummary> $orders */
    public static function of(Page $page, int $total, array $orders): self
    {
        return new self($page, $total, $orders);
    }

    /** @return array{page: int, perPage: int, total: int, orders: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'page' => $this->page->number,
            'perPage' => $this->page->size,
            'total' => $this->total,
            'orders' => array_map(static fn(OrderSummary $order): array => $order->toArray(), $this->orders),
        ];
    }
}
