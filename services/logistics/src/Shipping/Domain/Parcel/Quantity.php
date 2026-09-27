<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Parcel;

use Logistics\Shipping\Domain\Error\InvalidShipment;

/**
 * How many units of a product go into one parcel. The ceiling keeps any
 * weight or side times a quantity inside a 64-bit integer.
 */
final readonly class Quantity
{
    private const int MAX = 2_147_483_647;

    private function __construct(public int $value) {}

    public static function of(int $value): self
    {
        if ($value < 1 || $value > self::MAX) {
            throw InvalidShipment::because(sprintf('A parcel holds from 1 to %d units, got %d.', self::MAX, $value));
        }

        return new self($value);
    }
}
