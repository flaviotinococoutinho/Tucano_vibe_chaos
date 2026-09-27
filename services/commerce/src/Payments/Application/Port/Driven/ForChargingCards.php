<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driven;

use Commerce\Payments\Domain\CardToken;
use Commerce\Payments\Domain\ChargeRefused;
use Commerce\Payments\Domain\GatewayFailure;
use Commerce\Payments\Domain\GatewayUnavailable;
use Commerce\Payments\Domain\PaymentId;
use Tucano\SharedKernel\Money\Money;

/** The payment provider, in Payments' words. */
interface ForChargingCards
{
    /** @throws GatewayUnavailable when the provider is known to be down and nothing should be sent */
    public function ensureAvailable(): void;

    /**
     * Hands the charge to the provider, keyed by the payment id. Returns the provider's charge id.
     *
     * @throws GatewayUnavailable nothing was sent
     * @throws GatewayFailure the charge may or may not exist at the provider (a timeout, say)
     * @throws ChargeRefused the provider refused the request itself
     */
    public function charge(PaymentId $payment, Money $amount, CardToken $card): string;
}
