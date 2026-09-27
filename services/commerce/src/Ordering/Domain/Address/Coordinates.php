<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Address;

use Commerce\Ordering\Domain\Error\InvalidOrder;

final readonly class Coordinates
{
    public function __construct(public float $latitude, public float $longitude)
    {
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            throw InvalidOrder::because('Coordinates are outside the globe.');
        }
    }
}
