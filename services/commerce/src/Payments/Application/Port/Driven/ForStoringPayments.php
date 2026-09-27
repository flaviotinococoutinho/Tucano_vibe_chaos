<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driven;

use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;

interface ForStoringPayments
{
    public function save(Payment $payment): void;

    /** Locked for the rest of the transaction. */
    public function get(PaymentId $id): Payment;

    /** Like get(), or null when there is no such payment. */
    public function find(PaymentId $id): ?Payment;

    /**
     * Adds the payment unless the order already has one pending (a unique index allows
     * one), and returns whichever is pending now: two attempts share a payment, and so
     * the same key at the provider.
     */
    public function addUnlessPending(Payment $payment): Payment;
}
