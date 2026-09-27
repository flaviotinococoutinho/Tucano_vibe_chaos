<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use Commerce\Payments\Application\ChargeState;
use Commerce\Payments\Application\ProviderCharge;
use Commerce\Payments\Application\ReconcileResult;
use Commerce\Payments\Application\UseCase\ReconcilePayments;
use Commerce\Payments\Application\UseCase\RefundPayment;
use Commerce\Payments\Application\UseCase\SettlePayment;
use Commerce\Payments\Domain\GatewayUnavailable;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Payments\Domain\PaymentStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\Payments\FakeCardGateway;
use Tests\Doubles\Payments\FixedPayableOrders;
use Tests\Doubles\Payments\InMemoryPayments;
use Tests\Doubles\Payments\RecordedOrderSettlements;
use Tests\Doubles\Shared\DirectTransactions;
use Tests\Doubles\Shared\InMemoryInbox;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;
use Tucano\SharedKernel\Time\FrozenClock;

/** The decision table of UC-PAY-03, one row per test. */
final class ReconcilePaymentsTest extends TestCase
{
    private const string ORDER = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    private FrozenClock $clock;

    private InMemoryPayments $payments;

    private FakeCardGateway $gateway;

    private RecordedOrderSettlements $orders;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-09-27T12:10:00Z');
        $this->payments = new InMemoryPayments();
        $this->gateway = new FakeCardGateway();
        $this->orders = new RecordedOrderSettlements();
    }

    #[Test]
    public function a_payment_is_left_alone_until_it_has_been_quiet_for_a_minute(): void
    {
        $this->pendingPayment(startedAt: '2026-09-27T12:09:30Z');

        self::assertNull($this->reconciliation()->reconcileNext());
    }

    #[Test]
    public function the_payment_quiet_for_the_longest_goes_first(): void
    {
        $this->pendingPayment(startedAt: '2026-09-27T12:07:00Z', orderId: '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5e');
        $older = $this->pendingPayment(startedAt: '2026-09-27T12:05:00Z');

        $reconciled = $this->reconciliation()->reconcileNext();

        self::assertSame($older->id->toString(), $reconciled?->paymentId->toString());
    }

    #[Test]
    public function a_capture_whose_webhook_never_came_is_applied_as_the_webhook_would(): void
    {
        $payment = $this->pendingPayment();
        $this->providerHas($payment, ChargeState::Succeeded);

        $reconciled = $this->reconciliation()->reconcileNext();

        self::assertSame(ReconcileResult::Settled, $reconciled?->result);
        self::assertSame(PaymentStatus::Captured, $this->statusOf($payment));
        self::assertSame(FakeCardGateway::chargeIdOf($payment->id), $this->payments->get($payment->id)->chargeId);
        self::assertSame([self::ORDER], $this->orders->paid);
    }

    #[Test]
    public function a_decline_found_by_asking_cancels_the_order(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->has($payment->id, new ProviderCharge(FakeCardGateway::chargeIdOf($payment->id), ChargeState::Failed, 'card_declined'));

        $this->reconciliation()->reconcileNext();

        self::assertSame([PaymentStatus::Failed, 'card_declined'], [$this->statusOf($payment), $this->payments->get($payment->id)->failureReason]);
        self::assertSame([self::ORDER], $this->orders->cancelled);
    }

    #[Test]
    public function a_charge_still_processing_leaves_its_id_behind(): void
    {
        $payment = $this->pendingPayment();
        $this->providerHas($payment, ChargeState::Processing);

        $reconciled = $this->reconciliation()->reconcileNext();

        self::assertSame(ReconcileResult::InProgress, $reconciled?->result);
        self::assertSame(PaymentStatus::Pending, $this->statusOf($payment));
        self::assertSame(FakeCardGateway::chargeIdOf($payment->id), $this->payments->get($payment->id)->chargeId);
    }

    #[Test]
    public function without_a_charge_the_payment_waits_as_long_as_its_order_does(): void
    {
        $payment = $this->pendingPayment();

        $reconciled = $this->reconciliation(waitingOrders: [self::ORDER])->reconcileNext();

        self::assertSame(ReconcileResult::Waiting, $reconciled?->result);
        self::assertSame(PaymentStatus::Pending, $this->statusOf($payment));
    }

    #[Test]
    public function without_a_charge_and_without_an_order_waiting_tucano_gives_up(): void
    {
        $payment = $this->pendingPayment();

        $reconciled = $this->reconciliation()->reconcileNext();

        self::assertSame(ReconcileResult::Abandoned, $reconciled?->result);
        self::assertSame(PaymentStatus::Abandoned, $this->statusOf($payment));
        self::assertSame([], $this->orders->cancelled);
    }

    #[Test]
    public function a_charge_the_provider_lost_needs_a_person(): void
    {
        $payment = $this->pendingPayment();
        $payment->chargedAs('ch_lost', new DateTimeImmutable('2026-09-27T12:05:01Z'));

        $reconciled = $this->reconciliation()->reconcileNext();

        self::assertSame(ReconcileResult::NeedsAttention, $reconciled?->result);
        self::assertSame(PaymentStatus::Pending, $this->statusOf($payment));
    }

    #[Test]
    public function money_that_came_for_an_abandoned_payment_goes_back(): void
    {
        // A retry reached the provider just as Tucano gave up, and its webhook never came.
        $payment = $this->pendingPayment();
        $payment->abandon(new DateTimeImmutable('2026-09-27T12:05:01Z'));
        $payment->chargedAs(FakeCardGateway::chargeIdOf($payment->id), new DateTimeImmutable('2026-09-27T12:05:02Z'));
        $this->providerHas($payment, ChargeState::Succeeded);

        $reconciled = $this->reconciliation()->reconcileNext();

        self::assertSame(ReconcileResult::Settled, $reconciled?->result);
        self::assertSame(PaymentStatus::RefundRequested, $this->statusOf($payment));
        self::assertSame([], $this->orders->paid);
    }

    #[Test]
    public function a_requested_refund_goes_to_the_provider_keyed_by_the_payment(): void
    {
        $payment = $this->refundRequestedPayment();
        $this->providerHas($payment, ChargeState::Succeeded);

        $reconciled = $this->reconciliation()->reconcileNext();

        self::assertSame(ReconcileResult::RefundSent, $reconciled?->result);
        self::assertSame(
            [['payment' => $payment->id->toString(), 'charge' => FakeCardGateway::chargeIdOf($payment->id), 'cents' => 18990]],
            $this->gateway->refunds,
        );
        self::assertSame(PaymentStatus::RefundRequested, $this->statusOf($payment));
    }

    #[Test]
    public function a_refund_being_processed_is_left_to_finish(): void
    {
        $payment = $this->refundRequestedPayment();
        $this->providerHas($payment, ChargeState::Refunding);

        $reconciled = $this->reconciliation()->reconcileNext();

        self::assertSame(ReconcileResult::InProgress, $reconciled?->result);
        self::assertSame([], $this->gateway->refunds);
    }

    #[Test]
    public function a_refund_the_provider_confirmed_is_applied(): void
    {
        $payment = $this->refundRequestedPayment();
        $this->providerHas($payment, ChargeState::Refunded);

        $reconciled = $this->reconciliation()->reconcileNext();

        self::assertSame(ReconcileResult::Settled, $reconciled?->result);
        self::assertSame(PaymentStatus::Refunded, $this->statusOf($payment));
    }

    #[Test]
    public function a_refund_the_provider_refuses_needs_a_person(): void
    {
        $payment = $this->refundRequestedPayment();
        $this->providerHas($payment, ChargeState::Succeeded);
        $this->gateway->refusesRefunds('Charge is disputed.');

        $reconciled = $this->reconciliation()->reconcileNext();

        self::assertSame(ReconcileResult::NeedsAttention, $reconciled?->result);
    }

    #[Test]
    public function without_an_answer_the_payment_waits_for_a_later_round(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->timesOutOnce();
        $reconciliation = $this->reconciliation();

        $reconciled = $reconciliation->reconcileNext();

        self::assertSame(ReconcileResult::NoAnswer, $reconciled?->result);
        self::assertSame(PaymentStatus::Pending, $this->statusOf($payment));
        // Claiming touched it, so the next round leaves it alone until it is quiet again.
        self::assertNull($reconciliation->reconcileNext());
    }

    #[Test]
    public function an_open_circuit_claims_nothing(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->goesDownFor(20);

        try {
            $this->reconciliation()->reconcileNext();
            self::fail('The open circuit was expected to stop the round.');
        } catch (GatewayUnavailable $unavailable) {
            self::assertSame(20, $unavailable->retryAfterSeconds);
        }

        self::assertEquals(new DateTimeImmutable('2026-09-27T12:05:00Z'), $this->payments->get($payment->id)->updatedAt);
    }

    /** @param list<string> $waitingOrders the orders that can still be paid */
    private function reconciliation(array $waitingOrders = []): ReconcilePayments
    {
        $transactions = new DirectTransactions();

        return new ReconcilePayments(
            $transactions,
            $this->payments,
            $this->gateway,
            new FixedPayableOrders(array_fill_keys($waitingOrders, Money::of(18990, Currency::brl()))),
            new SettlePayment($transactions, new InMemoryInbox(), $this->payments, $this->orders, $this->clock),
            new RefundPayment($this->payments, $this->gateway),
            $this->clock,
            60,
        );
    }

    private function pendingPayment(string $startedAt = '2026-09-27T12:05:00Z', string $orderId = self::ORDER): Payment
    {
        $payment = Payment::start(PaymentId::generate(), $orderId, Money::of(18990, Currency::brl()), new DateTimeImmutable($startedAt));
        $this->payments->save($payment);

        return $payment;
    }

    /** Captured after its order expired, so the money has to go back. */
    private function refundRequestedPayment(): Payment
    {
        $payment = $this->pendingPayment();
        $at = new DateTimeImmutable('2026-09-27T12:05:01Z');
        $payment->chargedAs(FakeCardGateway::chargeIdOf($payment->id), $at);
        $payment->capture($at);
        $payment->requestRefund($at);

        return $payment;
    }

    private function providerHas(Payment $payment, ChargeState $state): void
    {
        $this->gateway->has($payment->id, new ProviderCharge(FakeCardGateway::chargeIdOf($payment->id), $state));
    }

    private function statusOf(Payment $payment): PaymentStatus
    {
        return $this->payments->get($payment->id)->status;
    }
}
