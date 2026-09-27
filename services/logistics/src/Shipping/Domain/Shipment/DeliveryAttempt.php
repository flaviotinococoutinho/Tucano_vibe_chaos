<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use DateTimeImmutable;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;

/**
 * One visit to the address: the proof of delivery when it worked, the reason
 * when it did not. The shipment hands its visits to the repository once, like
 * its transitions, and delivery_attempts keeps them.
 */
final readonly class DeliveryAttempt
{
    private function __construct(
        public int $number,
        public AttemptOutcome $outcome,
        public ?DeliveryFailure $failure,
        public ?ProofOfDelivery $proof,
        public DateTimeImmutable $at,
    ) {}

    public static function delivered(int $number, ProofOfDelivery $proof, DateTimeImmutable $at): self
    {
        return new self($number, AttemptOutcome::Delivered, null, $proof, $at);
    }

    public static function failed(int $number, DeliveryFailure $failure, DateTimeImmutable $at): self
    {
        $outcome = $failure === DeliveryFailure::RecipientRefused ? AttemptOutcome::Refused : AttemptOutcome::Failed;

        return new self($number, $outcome, $failure, null, $at);
    }
}
