<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Guard;

use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Shipment\DeliveryAttempts;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Transition\TransitionRequest;

/** The way back starts after the last attempt fails or after the recipient refuses the parcels. */
final readonly class ReturnAllowed extends TransitionGuard
{
    protected function verify(TransitionRequest $request): void
    {
        if ($request->leadsTo(ShipmentStatus::Returning) && !$request->attempts->allowReturn()) {
            throw TransitionRefused::returnNotJustified(DeliveryAttempts::LIMIT);
        }
    }
}
