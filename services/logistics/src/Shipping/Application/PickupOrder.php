<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Logistics\Shipping\Domain\Shipment\ShipmentSnapshot;
use Tucano\SharedKernel\Address\BrazilianState;

/** What the carrier needs to collect a shipment: where from, where to, how much. Nobody's name goes out. */
final readonly class PickupOrder
{
    public function __construct(
        public ShipmentSnapshot $shipment,
        public BrazilianState $originState,
    ) {}
}
