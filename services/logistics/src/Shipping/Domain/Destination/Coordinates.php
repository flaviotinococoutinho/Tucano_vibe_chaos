<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Destination;

use Logistics\Shipping\Domain\Error\InvalidShipment;

final readonly class Coordinates
{
    public function __construct(public float $latitude, public float $longitude)
    {
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            throw InvalidShipment::because('Coordinates are outside the globe.');
        }
    }
}
