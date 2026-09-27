<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Guard;

use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Transition\TransitionRequest;

/** A failed delivery says why: the reason decides between another visit and the way back. */
final readonly class FailureReasonRequired extends TransitionGuard
{
    protected function verify(TransitionRequest $request): void
    {
        if ($request->leadsTo(ShipmentStatus::DeliveryFailed) && $request->evidence->failure === null) {
            throw TransitionRefused::withoutFailureReason();
        }
    }
}
