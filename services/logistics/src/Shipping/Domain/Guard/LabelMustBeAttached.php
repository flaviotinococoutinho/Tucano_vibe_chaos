<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Guard;

use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Transition\TransitionRequest;

/** Nothing waits for pickup without its label; the label_before_pickup CHECK says the same in the database. */
final readonly class LabelMustBeAttached extends TransitionGuard
{
    protected function verify(TransitionRequest $request): void
    {
        if ($request->leadsTo(ShipmentStatus::ReadyForPickup) && $request->evidence->label === null) {
            throw TransitionRefused::withoutLabel();
        }
    }
}
