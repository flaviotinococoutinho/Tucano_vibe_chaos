<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Commerce\Ordering\Application\Port\Driving\ForExpiringOrders;
use Commerce\Payments\Application\Port\Driving\ForReconcilingPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

#[Group('integration')]
final class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    use PaysForAnOrder;

    private const string SECRET = 'whsec_local_payfake';

    private string $chargeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->placeAndPayAnOrder();
        $this->chargeId = (string) DB::table('payments')->where('id', $this->paymentId)->value('provider_charge_id');
    }

    #[Test]
    public function a_captured_charge_pays_the_order_and_sells_its_units(): void
    {
        $this->webhook('charge.succeeded')->assertOk()->assertJsonPath('result', 'applied');

        self::assertSame(['captured', 'paid'], [$this->paymentStatus(), $this->orderStatus()]);
        // LAB-CONSOLE-001 had 5 units in GRU1: 2 left the shelf, nothing stays reserved.
        self::assertSame([3, 0], $this->stock());
        self::assertSame(['committed'], DB::table('stock_reservations')->where('order_id', $this->orderId)->pluck('status')->all());
        $this->assertOrderPaidMatchesItsContract();
    }

    #[Test]
    public function the_same_event_twice_is_handled_once(): void
    {
        $event = 'evt_' . Str::ulid();
        $this->webhook('charge.succeeded', eventId: $event)->assertJsonPath('result', 'applied');

        $this->webhook('charge.succeeded', eventId: $event)->assertOk()->assertJsonPath('result', 'duplicate');

        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'tucano.commerce.order.paid')->count());
    }

    #[Test]
    public function a_declined_charge_cancels_the_order_and_puts_the_units_back(): void
    {
        $this->webhook('charge.failed', failureCode: 'card_declined')->assertOk()->assertJsonPath('result', 'applied');

        self::assertSame(['failed', 'cancelled'], [$this->paymentStatus(), $this->orderStatus()]);
        self::assertSame('card_declined', DB::table('payments')->where('id', $this->paymentId)->value('failure_reason'));
        self::assertSame([5, 0], $this->stock());
        self::assertSame(
            'payment_declined',
            DB::table('order_status_transitions')->where(['order_id' => $this->orderId, 'to_status' => 'cancelled'])->value('reason'),
        );
    }

    #[Test]
    public function money_that_arrives_after_the_order_expired_is_marked_for_refund(): void
    {
        $this->clock->moveTo('2026-09-27T12:16:00Z');
        $this->app->make(ForExpiringOrders::class)->expireNext();

        $this->webhook('charge.succeeded')->assertOk()->assertJsonPath('result', 'applied');

        self::assertSame(['refund_requested', 'cancelled'], [$this->paymentStatus(), $this->orderStatus()]);
        self::assertSame([5, 0], $this->stock());
    }

    #[Test]
    public function money_that_arrives_after_tucano_gave_up_goes_back(): void
    {
        // The answer with the charge id was lost, the order expired, and the reconciliation found no charge.
        DB::table('payments')->where('id', $this->paymentId)->update(['provider_charge_id' => null]);
        $this->clock->moveTo('2026-09-27T12:16:00Z');
        $this->app->make(ForExpiringOrders::class)->expireNext();
        $this->app->make(ForReconcilingPayments::class)->reconcileNext();
        self::assertSame('abandoned', $this->paymentStatus());

        $this->webhook('charge.succeeded')->assertOk()->assertJsonPath('result', 'applied');

        self::assertSame(['refund_requested', 'cancelled'], [$this->paymentStatus(), $this->orderStatus()]);
        self::assertSame($this->chargeId, DB::table('payments')->where('id', $this->paymentId)->value('provider_charge_id'));
    }

    #[Test]
    public function a_webhook_signed_with_another_secret_is_refused(): void
    {
        $this->webhook('charge.succeeded', secret: 'whsec_someone_else')
            ->assertBadRequest()
            ->assertJsonPath('detail', 'The PayFake-Signature is mismatch.');

        self::assertSame(['pending', 'pending_payment'], [$this->paymentStatus(), $this->orderStatus()]);
    }

    #[Test]
    public function what_is_not_ours_is_acknowledged_and_left_alone(): void
    {
        $this->webhook('charge.succeeded', reference: (string) Str::uuid7())->assertOk()->assertJsonPath('result', 'unknown_payment');
        $this->webhook('dispute.opened')->assertOk()->assertJsonPath('result', 'ignored');

        self::assertSame('pending', $this->paymentStatus());
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function webhook(string $type, ?string $eventId = null, ?string $failureCode = null, ?string $reference = null, string $secret = self::SECRET): TestResponse
    {
        $body = json_encode([
            'id' => $eventId ?? 'evt_' . Str::ulid(),
            'type' => $type,
            'createdAt' => $this->clock->now()->format(DATE_RFC3339_EXTENDED),
            'data' => array_filter([
                'chargeId' => $this->chargeId,
                'reference' => $reference ?? $this->paymentId,
                'amount' => ['value' => 599980, 'currency' => 'BRL'],
                'failureCode' => $failureCode,
            ], static fn(mixed $value): bool => $value !== null),
        ], JSON_THROW_ON_ERROR);
        $signedAt = $this->clock->now()->getTimestamp();
        $signature = sprintf('t=%d,v1=%s', $signedAt, hash_hmac('sha256', $signedAt . '.' . $body, $secret));

        // call() sends the body byte for byte; withHeaders() would not reach it, so the header goes as a server variable.
        return $this->call('POST', '/v1/webhooks/payfake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYFAKE_SIGNATURE' => $signature,
        ], $body);
    }

    private function assertOrderPaidMatchesItsContract(): void
    {
        $payload = DB::table('outbox_messages')->where('event_type', 'tucano.commerce.order.paid')->value('payload');
        $event = json_decode((string) $payload, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $event);

        $validator = new Validator();
        $validator->resolver()?->registerFile('urn:tucano:paid', dirname(__DIR__, 5) . '/contracts/events/commerce.order.paid.schema.json');
        self::assertTrue($validator->validate($event->data, 'urn:tucano:paid')->isValid(), (string) json_encode($event->data));
        // Logistics takes the store of the shipment from here (ADR 0031).
        self::assertSame('bemtevi', $event->data->store);
    }
}
