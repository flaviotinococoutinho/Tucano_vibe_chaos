<?php

declare(strict_types=1);

namespace Tests\Doubles\Payments;

use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Payments\Domain\PaymentNotFound;
use Commerce\Payments\Domain\PaymentStatus;

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
        return $this->payments[$id->toString()] ?? throw PaymentNotFound::withId($id->toString());
    }

    public function count(): int
    {
        return count($this->payments);
    }
}
