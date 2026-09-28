<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Application\CarrierEvent;
use Logistics\Shipping\Domain\Error\CarrierUnreachable;
use Logistics\Shipping\Domain\Shipment\ShipmentId;

/** The tracking history a carrier keeps of each pickup: every event, also the ones whose webhook never arrived. */
interface ForTrackingPickups
{
    /**
     * @return list<CarrierEvent> the events of the pickup booked for the shipment, oldest first; none when the carrier has no pickup for it
     *
     * @throws CarrierUnreachable when the carrier does not answer
     */
    public function eventsOf(ShipmentId $shipment): array;
}
