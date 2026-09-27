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

    private function newPayment(): Payment
    {
        return Payment::start(PaymentId::generate(), $this->orderId, Money::of(18990, Currency::brl()), new DateTimeImmutable('2026-09-27T12:05:00Z'));
    }
}
