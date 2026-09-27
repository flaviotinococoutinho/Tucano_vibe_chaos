<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\Port\Driven;

use Commerce\Inventory\Application\ReservationStrategy;

interface ForChoosingStrategy
{
    /** The same answer for the whole request: the isolation and the holds must agree. */
    public function current(): ReservationStrategy;
}
