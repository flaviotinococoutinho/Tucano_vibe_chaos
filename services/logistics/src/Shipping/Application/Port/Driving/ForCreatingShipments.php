<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\CreatedShipment;
use Logistics\Shipping\Application\PaidOrder;
use Logistics\Shipping\Application\ShipmentSkipped;
use Logistics\Shipping\Domain\Error\NoCarrierChosen;
use Logistics\Shipping\Domain\Error\ProductNotSyncedYet;

interface ForCreatingShipments
{
    /**
     * Creates the shipment of a paid order, once per event, unless the order was cancelled first.
     * The shipment belongs to the store of the order or, for an order from before the stores, to
     * the one store its products name; to none when neither says.
     *
     * @throws ProductNotSyncedYet when the catalog copy has no weight and size for a product yet
     * @throws NoCarrierChosen
     */
    public function create(PaidOrder $order): CreatedShipment|ShipmentSkipped;
}
