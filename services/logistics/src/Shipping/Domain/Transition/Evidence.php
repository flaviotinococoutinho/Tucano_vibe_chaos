<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Transition;

use Logistics\Shipping\Domain\Shipment\CancellationReason;

/**
 * What a transition brings along: the label, the hub that scanned the
 * shipment, the proof of delivery, why a delivery failed or why the shipment
 * was cancelled. Any of them may be missing when the request arrives; the
 * guard of each transition refuses it when the one it needs is not here.
 */
final readonly class Evidence
{
    public function __construct(
        public ?ShippingLabel $label = null,
        public ?Hub $hub = null,
        public ?ProofOfDelivery $proof = null,
        public ?DeliveryFailure $failure = null,
        public ?CancellationReason $cancellation = null,
    ) {}

    /** What the history keeps as the reason of the transition. */
    public function reason(): ?string
    {
        return $this->failure->value ?? $this->cancellation?->value;
    }

    /** What the history keeps as the place of the transition. */
    public function location(): ?string
    {
        return $this->hub?->name;
    }
}
