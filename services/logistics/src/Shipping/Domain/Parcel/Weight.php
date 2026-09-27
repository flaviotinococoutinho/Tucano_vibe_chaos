<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Parcel;

use Logistics\Shipping\Domain\Error\InvalidShipment;

/**
 * Grams, as a positive integer. The ceiling is what the INTEGER columns that
 * keep weights can hold; nothing near it ships as a parcel anyway.
 */
final readonly class Weight
{
    private const int MAX_GRAMS = 2_147_483_647;

    private function __construct(private int $grams) {}

    public static function ofGrams(int $grams): self
    {
        if ($grams < 1 || $grams > self::MAX_GRAMS) {
            throw InvalidShipment::because(sprintf('A weight goes from 1 to %d grams, got %d.', self::MAX_GRAMS, $grams));
        }

        return new self($grams);
    }

    public function times(Quantity $quantity): self
    {
        return self::ofGrams($this->grams * $quantity->value);
    }

    public function plus(self $other): self
    {
        return self::ofGrams($this->grams + $other->grams);
    }

    public function grams(): int
    {
        return $this->grams;
    }
}
