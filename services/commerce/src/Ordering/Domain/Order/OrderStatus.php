<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

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
