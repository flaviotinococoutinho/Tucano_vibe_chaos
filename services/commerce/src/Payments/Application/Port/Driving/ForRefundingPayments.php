<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driving;

use Commerce\Payments\Domain\ChargeRefused;
use Commerce\Payments\Domain\GatewayFailure;
use Commerce\Payments\Domain\GatewayUnavailable;
use Commerce\Payments\Domain\InvalidPayment;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Payments\Domain\PaymentNotFound;

interface ForRefundingPayments
{
    /**
     * Asks the provider to give the money of the payment back. Asking again is safe, and the
     * provider confirms later, by webhook or to the reconciliation (UC-PAY-02).
     *
     * @throws PaymentNotFound
     * @throws InvalidPayment the payment is not waiting for a refund
     * @throws GatewayUnavailable|GatewayFailure|ChargeRefused what the provider made of it
     */
    public function refund(PaymentId $payment): void;
}
