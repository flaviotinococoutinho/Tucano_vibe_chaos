<?php

declare(strict_types=1);

namespace Tests\Doubles\Payments;

use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Payments\Domain\PaymentNotFound;
use Commerce\Payments\Domain\PaymentStatus;
use DateTimeImmutable;

final class InMemoryPayments implements ForStoringPayments
{
    /** @var array<string, Payment> */
    private array $payments = [];

    public function addUnlessPending(Payment $payment): Payment
    {
        foreach ($this->payments as $stored) {
            if ($stored->orderId === $payment->orderId && $stored->status === PaymentStatus::Pending) {
                return $stored;
            }
        }
        $this->payments[$payment->id->toString()] = $payment;

        return $payment;
    }

    public function save(Payment $payment): void
    {
        $this->payments[$payment->id->toString()] = $payment;
    }

    public function get(PaymentId $id): Payment
    {
        return $this->find($id) ?? throw PaymentNotFound::withId($id->toString());
    }

    public function find(PaymentId $id): ?Payment
    {
        return $this->payments[$id->toString()] ?? null;
    }

    public function capturedFor(string $orderId): ?Payment
    {
        foreach ($this->payments as $payment) {
            if ($payment->orderId === $orderId && $payment->status === PaymentStatus::Captured) {
                return $payment;
            }
        }

        return null;
    }

    public function claimUnsettled(DateTimeImmutable $untouchedSince, DateTimeImmutable $now): ?Payment
    {
        $due = array_filter(
            $this->payments,
            static fn(Payment $payment): bool => $payment->updatedAt <= $untouchedSince && (
                in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::RefundRequested], true)
                || ($payment->status === PaymentStatus::Abandoned && $payment->chargeId !== null)
            ),
        );
        usort($due, static fn(Payment $a, Payment $b): int => $a->updatedAt <=> $b->updatedAt);
        $oldest = $due[0] ?? null;
        if ($oldest === null) {
            return null;
        }
        $touched = Payment::restore($oldest->id, $oldest->orderId, $oldest->amount, $oldest->status, $oldest->chargeId, $oldest->failureReason, $oldest->createdAt, $now);
        $this->save($touched);

        return $touched;
    }

    public function count(): int
    {
        return count($this->payments);
    }
}
