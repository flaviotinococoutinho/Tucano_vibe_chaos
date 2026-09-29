<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Commerce\Ordering\Domain\Error\OrderTransitionNotAllowed;

/**
 * The order lifecycle as one field instead of is_paid/is_shipped flags.
 * The match is exhaustive: a new case without its transitions fails static
 * analysis and throws UnhandledMatchError at runtime.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /**
     * The version an order has in this status, counted like Order::version: 1 once placed and
     * one more with each move. Every status has a single way in except cancelled, which counts
     * from the status the order was cancelled in; so one event tells how far its order had come.
     */
    public function orderVersion(?self $cameFrom = null): int
    {
        if ($cameFrom !== null) {
            return $cameFrom->canMoveTo($this) ? $cameFrom->orderVersion() + 1 : throw OrderTransitionNotAllowed::from($cameFrom, $this);
        }

        return match ($this) {
            self::PendingPayment => 1,
            self::Paid => 2,
            self::Shipped => 3,
            self::Delivered, self::Returned => 4,
            self::Cancelled => throw InvalidOrder::because('An order is cancelled from pending_payment or from paid, and its version depends on which.'),
        };
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered, self::Returned],
            self::Delivered, self::Cancelled, self::Returned => [],
        };
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }
}
