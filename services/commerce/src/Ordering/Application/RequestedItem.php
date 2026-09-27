<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Order\Quantity;
use Commerce\Ordering\Domain\Product\Sku;

final readonly class RequestedItem
{
    public function __construct(public Sku $sku, public Quantity $quantity) {}
}
