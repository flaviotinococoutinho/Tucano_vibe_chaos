<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Commerce\Ordering\Application\Port\Driving\ForExpiringOrders;
use Commerce\Payments\Application\ChargeState;
use Commerce\Payments\Application\Port\Driving\ForReconcilingPayments;
use Commerce\Payments\Application\ProviderCharge;
use Commerce\Payments\Application\ReconcileResult;
use Commerce\Payments\Domain\PaymentId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Doubles\Payments\FakeCardGateway;
use Tests\TestCase;

/** UC-PAY-03 against PostgreSQL, with the order, the stock and the inbox of the real flow. */
#[Group('integration')]
final class PaymentReconciliationTest extends TestCase
{
    use PaysForAnOrder;
    use RefreshDatabase;

    private ForReconcilingPayments $reconciliation;

    private PaymentId $payment;

    private string $chargeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->placeAndPayAnOrder();
        $this->reconciliation = $this->app->make(ForReconcilingPayments::class);
        $this->payment = PaymentId::fromString($this->paymentId);
        $this->chargeId = FakeCardGateway::chargeIdOf($this->payment);
    }

    #[Test]
    public function a_capture_whose_webhook_never_came_pays_the_order(): void
    {
        $this->gateway->has($this->payment, new ProviderCharge($this->chargeId, ChargeState::Succeeded));
        $this->clock->moveTo('2026-09-27T12:01:30Z');

        $reconciled = $this->reconciliation->reconcileNext();

        self::assertSame(ReconcileResult::Settled, $reconciled?->result);
        self::assertSame(['captured', 'paid'], [$this->paymentStatus(), $this->orderStatus()]);
        self::assertSame([3, 0], $this->stock());
        self::assertTrue(DB::table('inbox_messages')->where('message_id', "reconciliation:{$this->paymentId}:captured")->exists());
    }

    #[Test]
    public function money_that_came_too_late_goes_back_through_the_provider(): void
    {
        // The order expired while the charge was processing, and the charge succeeded after that.
        $this->clock->moveTo('2026-09-27T12:16:00Z');
        $this->app->make(ForExpiringOrders::class)->expireNext();
        $this->gateway->has($this->payment, new ProviderCharge($this->chargeId, ChargeState::Succeeded));

        $captured = $this->reconciliation->reconcileNext();
        $this->clock->moveTo('2026-09-27T12:17:01Z');
        $refundSent = $this->reconciliation->reconcileNext();
        $this->gateway->has($this->payment, new ProviderCharge($this->chargeId, ChargeState::Refunded));
        $this->clock->moveTo('2026-09-27T12:18:02Z');
        $refunded = $this->reconciliation->reconcileNext();

        self::assertSame(
            [ReconcileResult::Settled, ReconcileResult::RefundSent, ReconcileResult::Settled],
            [$captured?->result, $refundSent?->result, $refunded?->result],
        );
        self::assertSame([['payment' => $this->paymentId, 'charge' => $this->chargeId, 'cents' => 599980]], $this->gateway->refunds);
        self::assertSame(['refunded', 'cancelled'], [$this->paymentStatus(), $this->orderStatus()]);
        self::assertSame([5, 0], $this->stock());
    }
}
