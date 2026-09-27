<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\LabelOutcome;
use Logistics\Shipping\Domain\Error\LabelNotStored;
use Logistics\Shipping\Domain\Shipment\ShipmentId;

interface ForGeneratingLabels
{
    /**
     * Prints and stores the label of a created shipment, which then waits for pickup.
     * Running it again for the same shipment changes nothing.
     *
     * @throws LabelNotStored the storage failed; trying again is safe
     */
    public function generate(ShipmentId $shipment): LabelOutcome;
}
