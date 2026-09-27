<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

final readonly class PlacedOrder
{
    public function __construct(public OrderDetails $order, public Outcome $outcome) {}
}
