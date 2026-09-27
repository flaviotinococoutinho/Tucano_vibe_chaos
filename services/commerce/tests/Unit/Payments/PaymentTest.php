<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use Commerce\Payments\Domain\InvalidPayment;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Payments\Domain\PaymentStatus;
use Commerce\Payments\Domain\PaymentTransitionNotAllowed;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

final class PaymentTest extends TestCase
{
    #[Test]
    public function the_state_machine_matches_the_documented_one(): void
    {
        self::assertSame([PaymentStatus::Captured, PaymentStatus::Failed], PaymentStatus::Pending->next());
        self::assertSame([PaymentStatus::RefundRequested], PaymentStatus::Captured->next());
        self::assertSame([PaymentStatus::Refunded], PaymentStatus::RefundRequested->next());
        self::assertSame([], PaymentStatus::Failed->next());
        self::assertSame([], PaymentStatus::Refunded->next());
    }

    #[Test]
    public function a_failed_payment_keeps_the_reason_and_cannot_be_captured_later(): void
    {
        $payment = self::payment();
        $payment->fail('card_declined', new DateTimeImmutable('2026-09-27T12:06:00Z'));

        self::assertSame('card_declined', $payment->failureReason);
        $this->expectException(PaymentTransitionNotAllowed::class);

        $payment->capture(new DateTimeImmutable('2026-09-27T12:07:00Z'));
    }

    #[Test]
    public function the_same_charge_can_be_attached_twice_but_not_another_one(): void
    {
        $payment = self::payment();
        $payment->chargedAs('ch_1', new DateTimeImmutable('2026-09-27T12:06:00Z'));
        $payment->chargedAs('ch_1', new DateTimeImmutable('2026-09-27T12:06:01Z'));

        self::assertFalse($payment->awaitsCharge());
        $this->expectException(InvalidPayment::class);

        $payment->chargedAs('ch_2', new DateTimeImmutable('2026-09-27T12:06:02Z'));
    }

    private static function payment(): Payment
    {
        return Payment::start(PaymentId::generate(), '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d', Money::of(18990, Currency::brl()), new DateTimeImmutable('2026-09-27T12:05:00Z'));
    }
}
