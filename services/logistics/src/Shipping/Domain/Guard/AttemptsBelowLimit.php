<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Guard;

use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Shipment\DeliveryAttempts;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Transition\TransitionRequest;

/** Every trip to the address costs freight, so there is no fourth one. */
final readonly class AttemptsBelowLimit extends TransitionGuard
{
    protected function verify(TransitionRequest $request): void
    {
        if ($request->leadsTo(ShipmentStatus::OutForDelivery) && !$request->attempts->allowAnother()) {
            throw TransitionRefused::attemptsExhausted(DeliveryAttempts::LIMIT);
        }
    }
}
