<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderStatus;
use DateTimeImmutable;

/**
 * An order moved on, as its view in the customer's list learns it from commerce.orders.v2:
 * the status it reached, when, why for a cancellation, and the version the order has there.
 * The version is what keeps a view from ever taking a move older than the one it shows.
 */
final readonly class StatusMove
{
    private function __construct(
        public OrderId $orderId,
        public OrderStatus $status,
        public int $version,
        public DateTimeImmutable $at,
        public ?CancellationReason $cancellationReason,
    ) {}

    /** Paid, shipped, delivered or returned: each status has one way in, so it alone gives the version. */
    public static function to(OrderStatus $status, OrderId $orderId, DateTimeImmutable $at): self
    {
        return new self($orderId, $status, $status->orderVersion(), $at, null);
    }

    /** Cancelled has two ways in, and order.cancelled says which one: the status the order was in. */
    public static function cancelled(OrderId $orderId, CancellationReason $reason, OrderStatus $cancelledIn, DateTimeImmutable $at): self
    {
        return new self($orderId, OrderStatus::Cancelled, OrderStatus::Cancelled->orderVersion($cancelledIn), $at, $reason);
    }
}
