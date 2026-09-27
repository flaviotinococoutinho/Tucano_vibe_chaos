<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\Port\Driving;

use Commerce\Inventory\Application\ReservedStock;
use Commerce\Inventory\Application\StockRequest;
use Commerce\Inventory\Domain\InsufficientStock;
use Commerce\Inventory\Domain\StockContention;
use Commerce\Shared\Application\Isolation;

interface ForReservingStock
{
    /** The isolation the caller's transaction must have for the strategy in use. */
    public function requiredIsolation(): Isolation;

    /**
     * Holds every item in a single fulfillment center, inside the caller's transaction.
     *
     * @throws InsufficientStock when no center can hold them all
     * @throws StockContention when an optimistic reservation keeps losing the race
     */
    public function reserve(StockRequest $request): ReservedStock;
}
