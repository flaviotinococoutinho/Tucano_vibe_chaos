<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Product;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Stringable;

/** Stock keeping unit such as BOOK-DDD-001, stored as VARCHAR(32). */
final readonly class Sku implements Stringable
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{2,31}$/', $normalized) !== 1) {
            throw InvalidOrder::because(sprintf('"%s" is not a valid SKU.', $value));
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
