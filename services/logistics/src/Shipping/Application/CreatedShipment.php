<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Logistics\Shipping\Domain\Shipment\CarrierCode;
use Logistics\Shipping\Domain\Shipment\ShipmentReference;

final readonly class CreatedShipment
{
    public function __construct(public ShipmentReference $shipment, public CarrierCode $carrier) {}
}
