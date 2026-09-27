<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Application\PickupOrder;
use Logistics\Shipping\Domain\Error\PickupNotBooked;
use Logistics\Shipping\Domain\Error\PickupRefused;

/** The carriers, in Shipping's words. */
interface ForSchedulingPickups
{
    /**
     * @throws PickupNotBooked the pickup may or may not exist at the carrier
     * @throws PickupRefused the carrier refused the request itself
     */
    public function schedule(PickupOrder $order): void;
}
