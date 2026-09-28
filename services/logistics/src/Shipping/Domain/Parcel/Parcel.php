<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Parcel;

/** One physical package of a shipment. */
final readonly class Parcel
{
    private function __construct(public Weight $weight, public Dimensions $dimensions) {}

    public static function of(Weight $weight, Dimensions $dimensions): self
    {
        return new self($weight, $dimensions);
    }
}
