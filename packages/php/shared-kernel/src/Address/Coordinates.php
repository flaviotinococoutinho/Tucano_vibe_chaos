<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

final readonly class Coordinates
{
    public function __construct(public float $latitude, public float $longitude)
    {
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            throw InvalidAddress::because('Coordinates are outside the globe.');
        }
    }
}
