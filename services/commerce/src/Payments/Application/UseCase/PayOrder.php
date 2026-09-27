<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\UseCase;

use Commerce\Payments\Application\PaymentAttempt;
use Commerce\Payments\Application\PaymentDetails;
use Commerce\Payments\Application\PayOrderCommand;
use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Commerce\Payments\Application\Port\Driven\ForFindingPayableOrders;
use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Application\Port\Driving\ForPayingOrders;
use Commerce\Payments\Domain\CardToken;
use Commerce\Payments\Domain\GatewayFailure;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Shared\Application\Idempotency\Outcome;
use Commerce\Shared\Application\Port\Driven\ForRememberingRequests;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Time\Clock;

/**
 * The HTTP call to the provider happens outside any transaction: no row stays
 * locked while the network decides. Idempotency here means no second payment and
 * no second charge; a repeated request returns the payment as it is now, and
 * sends the charge again if it never reached the provider.
 */
#[UseCase('UC-PAY-01')]
final readonly class PayOrder implements ForPayingOrders
{
    private const string SCOPE = 'payments.pay';

    public function __construct(
        private ForRunningTransactions $transactions,
        private ForRememberingRequests $requests,
        private ForFindingPayableOrders $orders,
        private ForStoringPayments $payments,
        private ForChargingCards $gateway,
        private Clock $clock,
    ) {}

    public function payOrder(PayOrderCommand $command): PaymentAttempt
    {
        // An open circuit refuses before the key is claimed, so a later retry is a fresh attempt.
        $this->gateway->ensureAvailable();

        [$payment, $outcome] = $this->transactions->run(fn(): array => $this->pendingPayment($command));
        if ($payment->awaitsCharge()) {
            $payment = $this->charge($payment, $command->card);
        }

        return new PaymentAttempt(PaymentDetails::of($payment), $outcome);
    }

    /** @return array{Payment, Outcome} */
    private function pendingPayment(PayOrderCommand $command): array
    {
        $stored = $this->requests->recall(self::SCOPE, $command->idempotencyKey, $command->fingerprint());
        if ($stored !== null) {
            return [$this->payments->get(PaymentId::fromString((string) $stored['paymentId'])), Outcome::Replayed];
        }

        $order = $this->orders->payable($command->orderId);
        $payment = $this->payments->addUnlessPending(
            Payment::start(PaymentId::generate(), $order->orderId, $order->amountDue, $this->clock->now()),
        );
        $this->requests->remember(self::SCOPE, $command->idempotencyKey, PaymentDetails::of($payment)->toArray());

        return [$payment, Outcome::Fresh];
    }

    private function charge(Payment $payment, CardToken $card): Payment
    {
        try {
            $chargeId = $this->gateway->charge($payment->id, $payment->amount, $card);
        } catch (GatewayFailure) {
            // Outcome unknown: the payment stays pending, and the webhook (it carries the
            // payment id) or a retry with the same key settles it.
            return $payment;
        }

        return $this->transactions->run(function () use ($payment, $chargeId): Payment {
            $locked = $this->payments->get($payment->id);
            $locked->chargedAs($chargeId, $this->clock->now());
            $this->payments->save($locked);

            return $locked;
        });
    }
}
