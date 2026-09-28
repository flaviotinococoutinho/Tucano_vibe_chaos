<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\UseCase;

use Commerce\Payments\Application\ChargeState;
use Commerce\Payments\Application\OutcomeKind;
use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Commerce\Payments\Application\Port\Driven\ForFindingPayableOrders;
use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Application\Port\Driving\ForReconcilingPayments;
use Commerce\Payments\Application\Port\Driving\ForRefundingPayments;
use Commerce\Payments\Application\Port\Driving\ForSettlingPayments;
use Commerce\Payments\Application\ProviderCharge;
use Commerce\Payments\Application\ProviderOutcome;
use Commerce\Payments\Application\ReconciledPayment;
use Commerce\Payments\Application\ReconcileResult;
use Commerce\Payments\Application\SettleResult;
use Commerce\Payments\Domain\ChargeRefused;
use Commerce\Payments\Domain\GatewayFailure;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Payments\Domain\PaymentStatus;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Time\Clock;

/**
 * The pull side of the provider's outcomes. Webhooks get lost and answers time out,
 * so this job asks the provider what became of each payment still missing its final
 * word. One payment per call, like the order expiry, and the provider call happens
 * outside any transaction.
 */
#[UseCase('UC-PAY-03')]
final readonly class ReconcilePayments implements ForReconcilingPayments
{
    /** The failure reason of a payment whose charge the provider lost. */
    private const string CHARGE_LOST = 'charge_lost';

    public function __construct(
        private ForRunningTransactions $transactions,
        private ForStoringPayments $payments,
        private ForChargingCards $gateway,
        private ForFindingPayableOrders $orders,
        private ForSettlingPayments $settlements,
        private ForRefundingPayments $refunds,
        private Clock $clock,
        private int $quietSeconds,
        private int $lostChargeAfterSeconds,
    ) {}

    public function reconcileNext(): ?ReconciledPayment
    {
        // An open circuit stops the round before a payment is claimed.
        $this->gateway->ensureAvailable();
        $now = $this->clock->now();
        $payment = $this->payments->claimUnsettled($now->modify(sprintf('-%d seconds', $this->quietSeconds)), $now);
        if ($payment === null) {
            return null;
        }

        try {
            $charge = $this->gateway->chargeOf($payment->id);
        } catch (GatewayFailure) {
            // The claim keeps it out of the way until it is quiet again; then it is asked about again.
            return new ReconciledPayment($payment->id, $payment->status, null, ReconcileResult::NoAnswer);
        }

        return new ReconciledPayment($payment->id, $payment->status, $charge, $this->decide($payment, $charge));
    }

    /** The decision table of UC-PAY-03: the payment as Tucano sees it, the charge as the provider does. */
    private function decide(Payment $payment, ?ProviderCharge $charge): ReconcileResult
    {
        if ($charge === null) {
            return $this->withoutCharge($payment);
        }

        return match ([$payment->status, $charge->state]) {
            [PaymentStatus::Pending, ChargeState::Processing],
            [PaymentStatus::Abandoned, ChargeState::Processing] => $this->stillProcessing($payment, $charge),
            [PaymentStatus::Pending, ChargeState::Succeeded],
            [PaymentStatus::Abandoned, ChargeState::Succeeded] => $this->settle($payment, $charge, OutcomeKind::Captured),
            [PaymentStatus::Pending, ChargeState::Failed],
            [PaymentStatus::Abandoned, ChargeState::Failed] => $this->settle($payment, $charge, OutcomeKind::Failed),
            [PaymentStatus::RefundRequested, ChargeState::Succeeded] => $this->sendRefund($payment),
            [PaymentStatus::RefundRequested, ChargeState::Refunding] => ReconcileResult::InProgress,
            [PaymentStatus::RefundRequested, ChargeState::Refunded] => $this->settle($payment, $charge, OutcomeKind::Refunded),
            default => ReconcileResult::NeedsAttention,
        };
    }

    private function withoutCharge(Payment $payment): ReconcileResult
    {
        if ($payment->status === PaymentStatus::Pending && $payment->chargeId !== null) {
            return $this->lostCharge($payment);
        }
        // A refund without a charge, or money that turned up for an abandoned payment and then
        // vanished, may still be money at the provider: that is for a person to look at.
        if (!$payment->awaitsCharge()) {
            return ReconcileResult::NeedsAttention;
        }
        // While the order waits, a retry of the customer still sends the charge (UC-PAY-01).
        if ($this->orders->awaitsPayment($payment->orderId)) {
            return ReconcileResult::Waiting;
        }

        return $this->abandon($payment->id);
    }

    /**
     * The provider took the charge and no longer shows it. A search can lag behind the charges,
     * so the charge gets a window to turn up; once it closes, the payment fails the way a
     * declined one does, and an order still waiting for it is cancelled.
     */
    private function lostCharge(Payment $payment): ReconcileResult
    {
        if ($payment->createdAt > $this->clock->now()->modify(sprintf('-%d seconds', $this->lostChargeAfterSeconds))) {
            return ReconcileResult::ChargeMissing;
        }
        $result = $this->settlements->settle(new ProviderOutcome(
            sprintf('reconciliation:%s:charge_lost', $payment->id->toString()),
            $payment->id,
            (string) $payment->chargeId,
            OutcomeKind::Failed,
            self::CHARGE_LOST,
        ));

        return match ($result) {
            SettleResult::Applied => ReconcileResult::ChargeLost,
            SettleResult::Duplicate, SettleResult::AlreadySettled => ReconcileResult::AlreadySettled,
            SettleResult::UnknownPayment => ReconcileResult::NeedsAttention,
        };
    }

    private function abandon(PaymentId $id): ReconcileResult
    {
        return $this->transactions->run(function () use ($id): ReconcileResult {
            $payment = $this->payments->get($id);
            // A webhook, or a retry that reached the provider, may have come while it was asked.
            if (!$payment->awaitsCharge()) {
                return $payment->status === PaymentStatus::Pending ? ReconcileResult::InProgress : ReconcileResult::AlreadySettled;
            }
            $payment->abandon($this->clock->now());
            $this->payments->save($payment);

            return ReconcileResult::Abandoned;
        });
    }

    private function stillProcessing(Payment $payment, ProviderCharge $charge): ReconcileResult
    {
        if ($payment->chargeId === null) {
            // The answer to the charge request was lost: the charge id is news.
            $this->transactions->run(function () use ($payment, $charge): void {
                $locked = $this->payments->get($payment->id);
                $locked->chargedAs($charge->chargeId, $this->clock->now());
                $this->payments->save($locked);
            });
        }

        return ReconcileResult::InProgress;
    }

    /** The same path as a webhook, so an outcome is applied one way whichever side brings it. */
    private function settle(Payment $payment, ProviderCharge $charge, OutcomeKind $kind): ReconcileResult
    {
        $result = $this->settlements->settle(new ProviderOutcome(
            // Stable for a payment and an outcome, so the inbox takes it once.
            sprintf('reconciliation:%s:%s', $payment->id->toString(), strtolower($kind->name)),
            $payment->id,
            $charge->chargeId,
            $kind,
            $charge->failureReason,
        ));

        return match ($result) {
            SettleResult::Applied => ReconcileResult::Settled,
            SettleResult::Duplicate, SettleResult::AlreadySettled => ReconcileResult::AlreadySettled,
            SettleResult::UnknownPayment => ReconcileResult::NeedsAttention,
        };
    }

    private function sendRefund(Payment $payment): ReconcileResult
    {
        try {
            $this->refunds->refund($payment->id);
        } catch (GatewayFailure) {
            return ReconcileResult::NoAnswer;
        } catch (ChargeRefused) {
            return ReconcileResult::NeedsAttention;
        }

        return ReconcileResult::RefundSent;
    }
}
