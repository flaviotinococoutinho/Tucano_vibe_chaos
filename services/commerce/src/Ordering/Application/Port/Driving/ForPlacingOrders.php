<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Application\PlacedOrder;
use Commerce\Ordering\Application\PlaceOrderCommand;
use Commerce\Ordering\Domain\Error\ProductUnavailable;
use Commerce\Ordering\Domain\Error\StockNotReserved;
use Commerce\Shared\Application\Idempotency\IdempotencyKeyReused;

interface ForPlacingOrders
{
    /**
     * @throws ProductUnavailable
     * @throws StockNotReserved
     * @throws IdempotencyKeyReused
     */
    public function placeOrder(PlaceOrderCommand $command): PlacedOrder;
}
