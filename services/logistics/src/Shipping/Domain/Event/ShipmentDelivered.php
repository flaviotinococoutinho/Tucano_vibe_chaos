<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Event;

use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Logistics\Shipping\Domain\Shipment\StatusTransition;

/** The proof of delivery stays in Logistics: who received the parcels is personal data. */
final readonly class ShipmentDelivered extends ShipmentEvent
{
    public function __construct(ShipmentReference $shipment, StatusTransition $transition, private int $attempt)
    {
        parent::__construct($shipment, $transition);
    }

    protected function details(): array
    {
        return ['attempt' => $this->attempt];
    }
}
