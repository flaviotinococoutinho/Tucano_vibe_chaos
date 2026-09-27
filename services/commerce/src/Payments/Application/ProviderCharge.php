<?php

declare(strict_types=1);

namespace Commerce\Payments\Application;

/** A charge as the provider sees it now: what reconciliation reads when no webhook came. */
final readonly class ProviderCharge
{
    public function __construct(
        public string $chargeId,
        public ChargeState $state,
        public ?string $failureReason = null,
    ) {}
}
