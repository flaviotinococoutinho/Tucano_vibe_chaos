<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

use DateTimeImmutable;
use Tucano\SharedKernel\Money\Money;

/**
 * Aggregate root of Payments. The provider's vocabulary (charge, webhook,
 * failure codes) never gets in here: the adapters translate it at the border.
 */
final class Payment
{
    private function __construct(
        public readonly PaymentId $id,
        public readonly string $orderId,
        public readonly Money $amount,
        public private(set) PaymentStatus $status,
        public private(set) ?string $chargeId,
        public private(set) ?string $failureReason,
        public readonly DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
    ) {}

    public static function start(PaymentId $id, string $orderId, Money $amount, DateTimeImmutable $at): self
    {
        return new self($id, $orderId, $amount, PaymentStatus::Pending, null, null, $at, $at);
    }

    public static function restore(
        PaymentId $id,
        string $orderId,
        Money $amount,
        PaymentStatus $status,
        ?string $chargeId,
        ?string $failureReason,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $orderId, $amount, $status, $chargeId, $failureReason, $createdAt, $updatedAt);
    }

    /** Still waiting for the provider to take the charge: a retry with the same key sends it again. */
    public function awaitsCharge(): bool
    {
        return $this->status === PaymentStatus::Pending && $this->chargeId === null;
    }

    /** The provider took the charge for processing; the outcome comes later, by webhook. */
    public function chargedAs(string $chargeId, DateTimeImmutable $at): void
    {
        if ($this->chargeId !== null && $this->chargeId !== $chargeId) {
            throw InvalidPayment::because(sprintf('Payment %s already has charge %s, not %s.', $this->id, $this->chargeId, $chargeId));
        }
        $this->chargeId = $chargeId;
        $this->updatedAt = $at;
    }

    public function capture(DateTimeImmutable $at): void
    {
        $this->moveTo(PaymentStatus::Captured, $at);
    }

    public function fail(string $reason, DateTimeImmutable $at): void
    {
        $this->moveTo(PaymentStatus::Failed, $at);
        $this->failureReason = $reason;
    }

    /**
     * Stops waiting for a charge the provider never got. A payment the provider took (it has a
     * charge id) can only be ended by the provider's word.
     */
    public function abandon(DateTimeImmutable $at): void
    {
        if ($this->chargeId !== null) {
            throw InvalidPayment::because(sprintf('Payment %s has charge %s at the provider and cannot be abandoned.', $this->id, $this->chargeId));
        }
        $this->moveTo(PaymentStatus::Abandoned, $at);
    }

    public function requestRefund(DateTimeImmutable $at): void
    {
        $this->moveTo(PaymentStatus::RefundRequested, $at);
    }

    /** The charge whose money goes back. Only a payment waiting for its refund has one. */
    public function chargeToRefund(): string
    {
        if ($this->status !== PaymentStatus::RefundRequested || $this->chargeId === null) {
            throw InvalidPayment::because(sprintf('Payment %s is %s and has no refund to ask for.', $this->id, $this->status->value));
        }

        return $this->chargeId;
    }

    public function markRefunded(DateTimeImmutable $at): void
    {
        $this->moveTo(PaymentStatus::Refunded, $at);
    }

    private function moveTo(PaymentStatus $target, DateTimeImmutable $at): void
    {
        if (!$this->status->canMoveTo($target)) {
            throw PaymentTransitionNotAllowed::from($this->status, $target);
        }
        $this->status = $target;
        $this->updatedAt = $at;
    }
}
