<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Domain\Error\InvalidShipment;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Tucano\SharedKernel\Address\BrazilianState;

interface ForLocatingFulfillmentCenters
{
    /** @throws InvalidShipment when the center is not in the fulfillment_centers table */
    public function stateOf(FulfillmentCenterCode $center): BrazilianState;
}
