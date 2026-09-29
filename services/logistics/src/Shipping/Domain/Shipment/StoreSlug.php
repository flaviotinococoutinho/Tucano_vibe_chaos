<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use Logistics\Shipping\Domain\Error\InvalidShipment;
use Stringable;

/**
 * The immutable slug of a store of the platform (ADR 0031), such as sabia (VARCHAR(31)).
 * Logistics knows nothing else about a store: the catalog owns the registry, and the slug
 * travels with the products, the orders and the shipments.
 */
final readonly class StoreSlug implements Stringable
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,30}$/', $value) !== 1) {
            throw InvalidShipment::because(sprintf('"%s" is not a store slug.', $value));
        }

        return new self($value);
    }

    /**
     * The one store all of them name, or null when one names none or two name different
     * stores: no answer is better than the wrong store.
     */
    public static function sharedBy(?self ...$stores): ?self
    {
        $names = array_unique(array_map(static fn(?self $store): string => $store->value ?? '', $stores));

        return count($names) === 1 ? $stores[0] : null;
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
