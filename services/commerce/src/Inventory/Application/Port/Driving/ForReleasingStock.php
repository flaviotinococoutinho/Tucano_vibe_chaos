<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\Port\Driving;

interface ForReleasingStock
{
    /** Gives back every unit an order still holds. Releasing twice changes nothing the second time. */
    public function release(string $orderId): void;
}
