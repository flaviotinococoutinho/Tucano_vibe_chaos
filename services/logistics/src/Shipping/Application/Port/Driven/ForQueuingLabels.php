<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Domain\Shipment\ShipmentId;

/** The work queue of labels: each request is taken by one worker, and comes back if that worker fails. */
interface ForQueuingLabels
{
    public function queue(ShipmentId $shipment): void;
}
