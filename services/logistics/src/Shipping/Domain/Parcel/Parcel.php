<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Parcel;

/** One physical package of a shipment. */
final readonly class Parcel
{
    public function __construct(public Weight $weight, public Dimensions $dimensions) {}
}
