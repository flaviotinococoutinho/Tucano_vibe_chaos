<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\Port\Driving;

use Commerce\Inventory\Application\ReservedStock;
use Commerce\Inventory\Application\StockRequest;
use Commerce\Inventory\Domain\InsufficientStock;

interface ForReservingStock
{
    /**
     * Holds every item in a single fulfillment center, inside the caller's transaction.
     *
     * @throws InsufficientStock when no center can hold them all
     */
    public function reserve(StockRequest $request): ReservedStock;
}
