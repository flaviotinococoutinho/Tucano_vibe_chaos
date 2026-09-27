<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Guard;

use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Transition\TransitionRequest;

/** A shipment is in transit because a hub scanned it, and the history says which one. */
final readonly class HubRequired extends TransitionGuard
{
    protected function verify(TransitionRequest $request): void
    {
        if ($request->leadsTo(ShipmentStatus::InTransit) && $request->evidence->hub === null) {
            throw TransitionRefused::withoutHub();
        }
    }
}
