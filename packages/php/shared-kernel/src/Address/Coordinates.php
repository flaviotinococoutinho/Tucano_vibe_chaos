<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

final readonly class Coordinates
{
    private function __construct(public float $latitude, public float $longitude) {}

    public static function of(float $latitude, float $longitude): self
    {
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            throw InvalidAddress::because('Coordinates are outside the globe.');
        }

        return new self($latitude, $longitude);
    }
}
