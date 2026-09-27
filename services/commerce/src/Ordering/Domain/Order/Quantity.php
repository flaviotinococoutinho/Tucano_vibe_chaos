<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use Commerce\Ordering\Domain\Error\InvalidOrder;

final readonly class Quantity
{
    private const int MAX_PER_LINE = 10;

    private function __construct(public int $value) {}

    public static function of(int $value): self
    {
        if ($value < 1 || $value > self::MAX_PER_LINE) {
            throw InvalidOrder::because(sprintf('Each line takes between 1 and %d units, got %d.', self::MAX_PER_LINE, $value));
        }

        return new self($value);
    }
}
