<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shipping\Application\Port\Driven\ForQueuingLabels;
use Logistics\Shipping\Application\Port\Driving\ForRequestingLabels;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * The policy that starts UC-SHP-03: every shipment created gets its label. It
 * reacts to ShipmentCreated, which the outbox already made durable, so asking
 * for the label never becomes a second write next to the shipment's.
 */
#[UseCase('UC-SHP-03')]
final readonly class RequestLabel implements ForRequestingLabels
{
    public function __construct(private ForQueuingLabels $queue) {}

    public function request(ShipmentId $shipment): void
    {
        $this->queue->queue($shipment);
    }
}
