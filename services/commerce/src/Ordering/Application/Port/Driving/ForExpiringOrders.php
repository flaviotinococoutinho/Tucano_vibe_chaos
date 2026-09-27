<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Domain\Order\OrderId;

interface ForExpiringOrders
{
    /** Cancels the oldest unpaid order whose reservation ran out and returns it, or null when there is none. */
    public function expireNext(): ?OrderId;
}
