<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

use Commerce\Shared\Application\Idempotency\Outcome;

final readonly class PaymentAttempt
{
    public function __construct(public PaymentDetails $payment, public Outcome $outcome) {}
}
