<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Product;

use Logistics\Shipping\Domain\Error\InvalidShipment;
use Stringable;

/** Stock keeping unit such as BOOK-DDD-001, stored as VARCHAR(32). */
final readonly class Sku implements Stringable
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{2,31}$/', $normalized) !== 1) {
            throw InvalidShipment::because(sprintf('"%s" is not a valid SKU.', $value));
        }

        return new self($normalized);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
