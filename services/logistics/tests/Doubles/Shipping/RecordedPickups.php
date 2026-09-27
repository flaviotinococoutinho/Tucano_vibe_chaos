<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\PickupOrder;
use Logistics\Shipping\Application\Port\Driven\ForSchedulingPickups;

final class RecordedPickups implements ForSchedulingPickups
{
    /** @var list<PickupOrder> the pickups asked for, in order */
    public private(set) array $orders = [];

    public function schedule(PickupOrder $order): void
    {
        $this->orders[] = $order;
    }
}
