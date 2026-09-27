<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use DateTimeImmutable;

/** How long a new order keeps its stock while it waits for payment. */
final readonly class ReservationWindow
{
    private function __construct(public int $minutes) {}

    public static function ofMinutes(int $minutes): self
    {
        if ($minutes < 1 || $minutes > 1440) {
            throw InvalidOrder::because(sprintf('A reservation lasts from 1 minute to a day, got %d minutes.', $minutes));
        }

        return new self($minutes);
    }

    public function endsAt(DateTimeImmutable $start): DateTimeImmutable
    {
        return $start->modify(sprintf('+%d minutes', $this->minutes));
    }
}
