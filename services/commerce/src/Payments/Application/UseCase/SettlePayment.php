<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\UseCase;

use Commerce\Payments\Application\OutcomeKind;
use Commerce\Payments\Application\Port\Driven\ForSettlingOrders;
use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Application\Port\Driving\ForSettlingPayments;
use Commerce\Payments\Application\ProviderOutcome;
use Commerce\Payments\Application\SettleResult;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentStatus;
use Commerce\Shared\Application\Port\Driven\ForDeduplicatingMessages;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use DateTimeImmutable;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Time\Clock;

/**
 * One transaction for everything an outcome changes: the inbox entry, the
 * payment, the order, the stock and the event in the outbox. A failure anywhere
 * rolls it all back, the webhook answers 5xx, and the provider sends it again.
 */
#[UseCase('UC-PAY-02')]
final readonly class SettlePayment implements ForSettlingPayments
{
    private const string INBOX = 'payments.payfake-webhook';

    public function __construct(
        private ForRunningTransactions $transactions,
        private ForDeduplicatingMessages $inbox,
        private ForStoringPayments $payments,
        private ForSettlingOrders $orders,
        private Clock $clock,
    ) {}

    public function settle(ProviderOutcome $outcome): SettleResult
    {
        return $this->transactions->run(function () use ($outcome): SettleResult {
            if (!$this->inbox->firstTime(self::INBOX, $outcome->eventId)) {
                return SettleResult::Duplicate;
            }
            $payment = $this->payments->find($outcome->paymentId);
            if ($payment === null) {
                return SettleResult::UnknownPayment;
            }

            $now = $this->clock->now();
            // The charge id may be news: the answer to the charge request can be lost to a timeout.
            $payment->chargedAs($outcome->chargeId, $now);
            $result = match ($outcome->kind) {
                OutcomeKind::Captured => $this->captured($payment, $now),
                OutcomeKind::Failed => $this->failed($payment, $outcome->failureReason ?? 'declined', $now),
                OutcomeKind::Refunded => $this->refunded($payment, $now),
            };
            $this->payments->save($payment);

            return $result;
        });
    }

    private function captured(Payment $payment, DateTimeImmutable $now): SettleResult
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return SettleResult::AlreadySettled;
        }
        $payment->capture($now);
        if (!$this->orders->markPaid($payment->orderId, $now)) {
            // The reservation ran out before the money arrived: the money goes back (UC-PAY-04).
            $payment->requestRefund($now);
        }

        return SettleResult::Applied;
    }

    private function failed(Payment $payment, string $reason, DateTimeImmutable $now): SettleResult
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return SettleResult::AlreadySettled;
        }
        $payment->fail($reason, $now);
        $this->orders->cancelDeclined($payment->orderId, $now);

        return SettleResult::Applied;
    }

    private function refunded(Payment $payment, DateTimeImmutable $now): SettleResult
    {
        if ($payment->status !== PaymentStatus::RefundRequested) {
            return SettleResult::AlreadySettled;
        }
        $payment->markRefunded($now);

        return SettleResult::Applied;
    }
}
