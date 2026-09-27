<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

use Commerce\Payments\Domain\PaymentId;
use Commerce\Payments\Domain\PaymentStatus;

/** One line of the reconciliation report: the payment as it was, what the provider said, what was done. */
final readonly class ReconciledPayment
{
    public function __construct(
        public PaymentId $paymentId,
        public PaymentStatus $status,
        public ?ProviderCharge $charge,
        public ReconcileResult $result,
    ) {}
}
