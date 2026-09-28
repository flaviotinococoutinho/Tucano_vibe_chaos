<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use DateTimeImmutable;

/** One step of the order state machine, kept for the append-only history. */
final readonly class StatusTransition
{
    private function __construct(
        public ?OrderStatus $from,
        public OrderStatus $to,
        public DateTimeImmutable $at,
        public ?string $reason = null,
    ) {}

    /** The status the aggregate is born in: there is nothing before it. */
    public static function initial(OrderStatus $status, DateTimeImmutable $at): self
    {
        return new self(null, $status, $at);
    }

    public static function between(OrderStatus $from, OrderStatus $to, DateTimeImmutable $at, ?string $reason = null): self
    {
        return new self($from, $to, $at, $reason);
    }
}
