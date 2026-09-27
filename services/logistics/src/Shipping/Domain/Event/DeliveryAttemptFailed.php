<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Event;

use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Logistics\Shipping\Domain\Shipment\StatusTransition;

final readonly class DeliveryAttemptFailed extends ShipmentEvent
{
    public function __construct(ShipmentReference $shipment, StatusTransition $transition, private int $attempt)
    {
        parent::__construct($shipment, $transition);
    }

    protected function details(): array
    {
        return ['attempt' => $this->attempt, 'reason' => $this->transition->reason];
    }
}
