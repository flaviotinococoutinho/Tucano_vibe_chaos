<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Store;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Stringable;

/**
 * The store a product and an order belong to (ADR 0031), known by its slug, such as sabia:
 * immutable, and the same in every service and every event. The catalog owns the store (its
 * name, tagline and palette); Ordering keeps only the slug, stored as VARCHAR(31).
 */
final readonly class StoreSlug implements Stringable
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,30}$/', $value) !== 1) {
            throw InvalidOrder::because(sprintf('"%s" is not the slug of a store.', $value));
        }

        return new self($value);
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
