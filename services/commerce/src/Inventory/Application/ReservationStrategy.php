<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application;

use Commerce\Shared\Application\Isolation;

/** How a unit of stock is taken when several buyers want it at the same time. The overselling lab compares them. */
enum ReservationStrategy: string
{
    case Atomic = 'atomic';
    case Pessimistic = 'pessimistic';
    case Optimistic = 'optimistic';
    case Serializable = 'serializable';
    case Naive = 'naive';

    public function isolation(): Isolation
    {
        return match ($this) {
            self::Serializable => Isolation::Serializable,
            self::Atomic, self::Pessimistic, self::Optimistic, self::Naive => Isolation::ReadCommitted,
        };
    }
}
