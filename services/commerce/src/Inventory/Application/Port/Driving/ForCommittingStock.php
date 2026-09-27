<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\Port\Driving;

interface ForCommittingStock
{
    /** The order was paid: what it held leaves the shelf for good. Committing twice changes nothing. */
    public function commit(string $orderId): void;
}
