<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Product;

use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Parcel;
use Logistics\Shipping\Domain\Parcel\Quantity;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Shipment\StoreSlug;

/**
 * What Logistics knows about a product: the local snapshot of its weight and size, and the
 * store it belongs to, unknown until a snapshot that says it arrives.
 */
final readonly class CatalogProduct
{
    public function __construct(public Sku $sku, public ?StoreSlug $store, public Weight $weight, public Dimensions $dimensions) {}

    /** One parcel per order line: every unit weighs the same and they go stacked in one box. */
    public function packed(Quantity $quantity): Parcel
    {
        return Parcel::of($this->weight->times($quantity), $this->dimensions->stacked($quantity));
    }
}
