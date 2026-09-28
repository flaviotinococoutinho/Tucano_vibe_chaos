<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use Commerce\Payments\Application\RefundRequest;
use Commerce\Payments\Application\UseCase\RequestOrderRefund;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Payments\Domain\PaymentStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\Payments\InMemoryPayments;
use Tests\Doubles\Shared\DirectTransactions;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;
use Tucano\SharedKernel\Time\FrozenClock;

final class RequestOrderRefundTest extends TestCase
{
    private const string ORDER = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    private InMemoryPayments $payments;

    private RequestOrderRefund $refunds;

    protected function setUp(): void
    {
        $this->payments = new InMemoryPayments();
        $this->refunds = new RequestOrderRefund(new DirectTransactions(), $this->payments, new FrozenClock('2026-09-27T18:05:00Z'));
    }

    #[Test]
    public function the_captured_payment_of_a_returned_order_asks_for_the_money_back(): void
    {
        $payment = self::payment();
        $payment->chargedAs('ch_1', new DateTimeImmutable('2026-09-27T12:01:00Z'));
        $payment->capture(new DateTimeImmutable('2026-09-27T12:01:00Z'));
        $this->payments->save($payment);

        self::assertSame(RefundRequest::Requested, $this->refunds->refundOrder(self::ORDER));
        self::assertSame(PaymentStatus::RefundRequested, $this->payments->get($payment->id)->status);
    }

    #[Test]
    public function an_order_without_a_captured_payment_has_nothing_to_refund(): void
    {
        $this->payments->save(self::payment());

        self::assertSame(RefundRequest::NothingToRefund, $this->refunds->refundOrder(self::ORDER));
        self::assertSame(RefundRequest::NothingToRefund, $this->refunds->refundOrder('01999a1e-0000-7000-8000-000000000000'));
    }

    private static function payment(): Payment
    {
        return Payment::start(PaymentId::generate(), self::ORDER, Money::of(18990, Currency::brl()), new DateTimeImmutable('2026-09-27T12:00:00Z'));
    }
}
