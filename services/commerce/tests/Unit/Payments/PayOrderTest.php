<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use Commerce\Payments\Application\PayOrderCommand;
use Commerce\Payments\Application\UseCase\PayOrder;
use Commerce\Payments\Domain\CardToken;
use Commerce\Payments\Domain\GatewayUnavailable;
use Commerce\Payments\Domain\OrderNotPayable;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Shared\Application\Idempotency\IdempotencyKey;
use Commerce\Shared\Application\Idempotency\Outcome;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\Payments\FakeCardGateway;
use Tests\Doubles\Payments\FixedPayableOrders;
use Tests\Doubles\Payments\InMemoryPayments;
use Tests\Doubles\Shared\DirectTransactions;
use Tests\Doubles\Shared\InMemoryRequestMemory;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;
use Tucano\SharedKernel\Time\FrozenClock;

final class PayOrderTest extends TestCase
{
    private const string ORDER = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    private InMemoryPayments $payments;

    private FakeCardGateway $gateway;

    private PayOrder $payOrder;

    protected function setUp(): void
    {
        $this->payments = new InMemoryPayments();
        $this->gateway = new FakeCardGateway();
        $this->payOrder = new PayOrder(
            new DirectTransactions(),
            new InMemoryRequestMemory(),
            new FixedPayableOrders([self::ORDER => Money::of(18990, Currency::brl())]),
            $this->payments,
            $this->gateway,
            new FrozenClock('2026-09-27T12:05:00Z'),
        );
    }

    #[Test]
    public function the_charge_goes_to_the_provider_keyed_by_the_payment(): void
    {
        $attempt = $this->payOrder->payOrder(self::command('key-1'));

        self::assertSame(Outcome::Fresh, $attempt->outcome);
        self::assertSame(['status' => 'pending', 'amount' => ['amount' => 18990, 'currency' => 'BRL']], array_intersect_key($attempt->payment->toArray(), ['status' => 0, 'amount' => 0]));
        self::assertSame([$attempt->payment->paymentId()], $this->gateway->charged);
        self::assertNotNull($this->payments->get(PaymentId::fromString($attempt->payment->paymentId()))->chargeId);
    }

    #[Test]
    public function the_same_request_again_charges_nothing_new(): void
    {
        $first = $this->payOrder->payOrder(self::command('key-1'));

        $again = $this->payOrder->payOrder(self::command('key-1'));

        self::assertSame(Outcome::Replayed, $again->outcome);
        self::assertSame($first->payment->paymentId(), $again->payment->paymentId());
        self::assertCount(1, $this->gateway->charged);
    }

    #[Test]
    public function after_a_timeout_a_retry_sends_the_same_payment_again(): void
    {
        $this->gateway->timesOutOnce();
        $first = $this->payOrder->payOrder(self::command('key-1'));
        self::assertNull($this->payments->get(PaymentId::fromString($first->payment->paymentId()))->chargeId);

        $this->payOrder->payOrder(self::command('key-1'));

        // Same payment id twice: the provider sees the same Idempotency-Key and charges once.
        self::assertSame([$first->payment->paymentId(), $first->payment->paymentId()], $this->gateway->charged);
        self::assertNotNull($this->payments->get(PaymentId::fromString($first->payment->paymentId()))->chargeId);
    }

    #[Test]
    public function two_attempts_while_one_is_pending_share_the_payment(): void
    {
        $this->gateway->timesOutOnce();
        $first = $this->payOrder->payOrder(self::command('key-1'));

        $second = $this->payOrder->payOrder(self::command('key-2'));

        self::assertSame($first->payment->paymentId(), $second->payment->paymentId());
        self::assertSame(1, $this->payments->count());
    }

    #[Test]
    public function an_open_circuit_refuses_before_anything_is_stored(): void
    {
        $this->gateway->goesDownFor(15);

        try {
            $this->payOrder->payOrder(self::command('key-1'));
            self::fail('The payment should have been refused.');
        } catch (GatewayUnavailable $unavailable) {
            self::assertSame(15, $unavailable->retryAfterSeconds);
        }

        self::assertSame(0, $this->payments->count());
        self::assertSame([], $this->gateway->charged);
    }

    #[Test]
    public function an_order_that_cannot_be_paid_is_refused(): void
    {
        $this->expectException(OrderNotPayable::class);

        $this->payOrder->payOrder(new PayOrderCommand(IdempotencyKey::of('key-1'), '01999a1e-0000-7000-8000-000000000000', CardToken::of('tok_visa')));
    }

    private static function command(string $key): PayOrderCommand
    {
        return new PayOrderCommand(IdempotencyKey::of($key), self::ORDER, CardToken::of('tok_visa'));
    }
}
