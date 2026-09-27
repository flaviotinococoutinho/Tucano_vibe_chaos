<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Application\LabelDocument;
use Logistics\Shipping\Domain\Shipment\ShipmentSnapshot;

/** Lays the label out in the format the warehouse printers take. */
interface ForPrintingLabels
{
    public function print(ShipmentSnapshot $shipment): LabelDocument;
}
