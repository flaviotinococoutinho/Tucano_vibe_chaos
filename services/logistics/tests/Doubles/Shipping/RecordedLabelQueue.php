<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\Port\Driven\ForQueuingLabels;
use Logistics\Shipping\Domain\Shipment\ShipmentId;

final class RecordedLabelQueue implements ForQueuingLabels
{
    /** @var list<string> the shipment ids queued, in order */
    public private(set) array $queued = [];

    public function queue(ShipmentId $shipment): void
    {
        $this->queued[] = $shipment->toString();
    }
}
