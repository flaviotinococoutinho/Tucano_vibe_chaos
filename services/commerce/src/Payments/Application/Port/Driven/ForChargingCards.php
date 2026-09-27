<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driven;

use Commerce\Payments\Application\ProviderCharge;
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

    /**
     * The charge made for the payment, as the provider sees it now, or null when it has none.
     * Found by the payment id, the reference, because the answer with the charge id can be lost.
     *
     * @throws GatewayUnavailable nothing was sent
     * @throws GatewayFailure no usable answer, so nothing is known
     */
    public function chargeOf(PaymentId $payment): ?ProviderCharge;

    /**
     * Asks for the whole amount back, keyed by the payment id: asking twice refunds once. The
     * provider takes it for processing, and its outcome comes later.
     *
     * @throws GatewayUnavailable nothing was sent
     * @throws GatewayFailure the refund may or may not exist at the provider
     * @throws ChargeRefused the provider refused it, a charge it cannot refund
     */
    public function refund(PaymentId $payment, string $chargeId, Money $amount): void;
}
