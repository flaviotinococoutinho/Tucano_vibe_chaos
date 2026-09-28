<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driven;

use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use DateTimeImmutable;

interface ForStoringPayments
{
    public function save(Payment $payment): void;

    /** Locked for the rest of the transaction. */
    public function get(PaymentId $id): Payment;

    /** Like get(), or null when there is no such payment. */
    public function find(PaymentId $id): ?Payment;

    /** The captured payment of the order, locked for the rest of the transaction; an order has one success at most. */
    public function capturedFor(string $orderId): ?Payment;

    /**
     * Adds the payment unless the order already has one pending (a unique index allows
     * one), and returns whichever is pending now: two attempts share a payment, and so
     * the same key at the provider.
     */
    public function addUnlessPending(Payment $payment): Payment;

    /**
     * Of the payments still missing the provider's final word and untouched since the given
     * instant, the one quiet for the longest, or null. Claiming touches it: other copies of
     * the job skip it, and one that dies leaves it to a later round (a lease).
     */
    public function claimUnsettled(DateTimeImmutable $untouchedSince, DateTimeImmutable $now): ?Payment;
}
