<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Domain\Destination\Destination;
use Logistics\Shipping\Domain\Error\NoCarrierChosen;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Shipment\CarrierCode;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;

/** Shipping's view of Carrier Selection: who takes these parcels from there to there. */
interface ForChoosingCarriers
{
    /** @throws NoCarrierChosen when no carrier takes the parcels, or the origin is unknown */
    public function choose(FulfillmentCenterCode $origin, Destination $destination, Parcels $parcels): CarrierCode;
}
