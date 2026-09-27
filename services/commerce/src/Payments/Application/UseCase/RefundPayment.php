<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\UseCase;

use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Application\Port\Driving\ForRefundingPayments;
use Commerce\Payments\Domain\PaymentId;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * The money goes back in full, keyed by the payment id, so a refund asked twice (a
 * lost answer, a crash between the call and the log) is made once. The payment
 * stays refund_requested until the provider confirms.
 */
#[UseCase('UC-PAY-04')]
final readonly class RefundPayment implements ForRefundingPayments
{
    public function __construct(private ForStoringPayments $payments, private ForChargingCards $gateway) {}

    public function refund(PaymentId $payment): void
    {
        // A plain read: the provider call below happens outside any transaction.
        $refundable = $this->payments->get($payment);

        $this->gateway->refund($refundable->id, $refundable->chargeToRefund(), $refundable->amount);
    }
}
