<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Logistics\Shipping\Domain\Parcel\Quantity;
use Logistics\Shipping\Domain\Product\Sku;

/** A line of a paid order as Logistics reads it: which product and how many units. */
final readonly class OrderLine
{
    public function __construct(public Sku $sku, public Quantity $quantity) {}
}
