<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use DateTimeImmutable;

/** One step of the order state machine, kept for the append-only history. */
final readonly class StatusTransition
{
    public function __construct(
        public ?OrderStatus $from,
        public OrderStatus $to,
        public DateTimeImmutable $at,
        public ?string $reason = null,
    ) {}
}
