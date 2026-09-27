<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Product;

use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Parcel;
use Logistics\Shipping\Domain\Parcel\Quantity;
use Logistics\Shipping\Domain\Parcel\Weight;

/** What Logistics knows about a product: the local snapshot of its weight and size. */
final readonly class CatalogProduct
{
    public function __construct(public Sku $sku, public Weight $weight, public Dimensions $dimensions) {}

    /** One parcel per order line: every unit weighs the same and they go stacked in one box. */
    public function packed(Quantity $quantity): Parcel
    {
        return new Parcel($this->weight->times($quantity), $this->dimensions->stacked($quantity));
    }
}
