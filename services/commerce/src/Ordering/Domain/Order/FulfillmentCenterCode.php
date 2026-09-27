<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Stringable;

/** Fixed 4-character warehouse code (CHAR(4)), such as GRU1. */
final readonly class FulfillmentCenterCode implements Stringable
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^[A-Z]{3}\d$/', $value) !== 1) {
            throw InvalidOrder::because(sprintf('"%s" is not a fulfillment center code.', $value));
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
