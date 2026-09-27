<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

use Commerce\Payments\Domain\PaymentId;

final readonly class ProviderOutcome
{
    public function __construct(
        public string $eventId,
        public PaymentId $paymentId,
        public string $chargeId,
        public OutcomeKind $kind,
        public ?string $failureReason = null,
    ) {}
}
