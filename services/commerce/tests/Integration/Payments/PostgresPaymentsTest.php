<?php

declare(strict_types=1);

namespace Tests\Integration\Payments;

use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use Database\Seeders\FulfillmentCenterSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Builders\OrderBuilder;
use Tests\TestCase;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

#[Group('integration')]
final class PostgresPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private ForStoringPayments $payments;

    private string $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FulfillmentCenterSeeder::class);
        $order = OrderBuilder::anOrder()->place();
        $this->app->make(ForStoringOrders::class)->add($order);
        $this->orderId = $order->id()->toString();
        $this->payments = $this->app->make(ForStoringPayments::class);
    }

    #[Test]
    public function a_second_payment_while_one_is_pending_gets_the_first_one(): void
    {
        $first = $this->payments->addUnlessPending($this->newPayment());

        $second = $this->payments->addUnlessPending($this->newPayment());

        self::assertTrue($first->id->equals($second->id));
    }

    #[Test]
    public function after_a_failure_a_new_payment_can_start(): void
    {
        $first = $this->payments->addUnlessPending($this->newPayment());
        $first->fail('card_declined', new DateTimeImmutable('2026-09-27T12:06:00Z'));
        $this->payments->save($first);

        $second = $this->payments->addUnlessPending($this->newPayment());

        self::assertFalse($first->id->equals($second->id));
        self::assertSame('card_declined', $this->payments->get($first->id)->failureReason);
    }

    #[Test]
    public function the_reconciliation_claims_the_quietest_payment_first_and_touches_it(): void
    {
        $older = $this->payments->addUnlessPending($this->newPayment());
        $newer = $this->payments->addUnlessPending($this->newPayment($this->anotherOrder(), '2026-09-27T12:06:00Z'));
        $untouchedSince = new DateTimeImmutable('2026-09-27T12:09:00Z');
        $now = new DateTimeImmutable('2026-09-27T12:10:00Z');

        $first = $this->payments->claimUnsettled($untouchedSince, $now);
        $second = $this->payments->claimUnsettled($untouchedSince, $now);

        self::assertSame([$older->id->toString(), $newer->id->toString()], [$first?->id->toString(), $second?->id->toString()]);
        self::assertEquals($now, $this->payments->get($older->id)->updatedAt);
        // Both were touched: neither is quiet enough for another round yet.
        self::assertNull($this->payments->claimUnsettled($untouchedSince, $now));
    }

    #[Test]
    public function only_payments_missing_the_providers_word_are_claimed(): void
    {
        $at = new DateTimeImmutable('2026-09-27T12:05:00Z');
        $captured = $this->newPayment();
        $captured->chargedAs('ch_captured', $at);
        $captured->capture($at);
        $failed = $this->newPayment($this->anotherOrder());
        $failed->fail('card_declined', $at);
        $abandoned = $this->newPayment($this->anotherOrder());
        $abandoned->abandon($at);
        $turnedUp = $this->newPayment($this->anotherOrder());
        $turnedUp->abandon($at);
        $turnedUp->chargedAs('ch_turned_up', $at);
        $refunding = $this->newPayment($this->anotherOrder());
        $refunding->chargedAs('ch_refunding', $at);
        $refunding->capture($at);
        $refunding->requestRefund($at);
        foreach ([$captured, $failed, $abandoned, $turnedUp, $refunding] as $payment) {
            // Inserted as it is, then saved for the columns the insert leaves out (the charge id).
            $this->payments->addUnlessPending($payment);
            $this->payments->save($payment);
        }

        $claimed = [];
        while (($payment = $this->payments->claimUnsettled(new DateTimeImmutable('2026-09-27T12:09:00Z'), new DateTimeImmutable('2026-09-27T12:10:00Z'))) !== null) {
            $claimed[] = $payment->id->toString();
        }

        self::assertEqualsCanonicalizing([$turnedUp->id->toString(), $refunding->id->toString()], $claimed);
    }

    private function newPayment(?string $orderId = null, string $at = '2026-09-27T12:05:00Z'): Payment
    {
        return Payment::start(PaymentId::generate(), $orderId ?? $this->orderId, Money::of(18990, Currency::brl()), new DateTimeImmutable($at));
    }

    private function anotherOrder(): string
    {
        $order = OrderBuilder::anOrder()->place();
        $this->app->make(ForStoringOrders::class)->add($order);

        return $order->id()->toString();
    }
}
