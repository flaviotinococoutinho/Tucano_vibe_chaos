<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Domain\Shipment\ShipmentId;

interface ForRequestingLabels
{
    /** Asks for the label of a shipment that was just created; it comes later, from the label queue. */
    public function request(ShipmentId $shipment): void;
}
