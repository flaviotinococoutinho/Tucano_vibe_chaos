<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Guard;

use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Transition\TransitionRequest;

/** A delivery without the name and the document of who received it did not happen. */
final readonly class ProofOfDeliveryRequired extends TransitionGuard
{
    protected function verify(TransitionRequest $request): void
    {
        if ($request->leadsTo(ShipmentStatus::Delivered) && $request->evidence->proof === null) {
            throw TransitionRefused::withoutProofOfDelivery();
        }
    }
}
