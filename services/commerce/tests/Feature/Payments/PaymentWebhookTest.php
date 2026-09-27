<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Commerce\Ordering\Application\Port\Driving\ForExpiringOrders;
use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Database\Seeders\FulfillmentCenterSeeder;
use Database\Seeders\StockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\Doubles\Payments\FakeCardGateway;
use Tests\TestCase;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\FrozenClock;

#[Group('integration')]
final class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'whsec_local_payfake';

    private FrozenClock $clock;

    private string $orderId;

    private string $paymentId;

    private string $chargeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock('2026-09-27T12:00:00Z');
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(ForChargingCards::class, new FakeCardGateway());
        $this->seed([FulfillmentCenterSeeder::class, StockSeeder::class]);
        DB::table('product_snapshots')->insert([
            'product_id' => (string) Str::uuid7(),
            'sku' => 'LAB-CONSOLE-001',
            'name' => 'Console portátil edição limitada',
            'price_cents' => 299990,
            'currency' => 'BRL',
            'status' => 'active',
            'catalog_version' => 1,
        ]);
        $this->orderId = (string) $this->postJson('/v1/orders', [
            'customer' => ['id' => (string) Str::uuid7(), 'name' => 'Ana Souza', 'email' => 'ana@example.com'],
            'shippingAddress' => ['street' => 'Avenida Paulista', 'number' => '1000', 'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP', 'postalCode' => '01310-100'],
            'items' => [['sku' => 'LAB-CONSOLE-001', 'quantity' => 2]],
        ], ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('orderId');
        $this->paymentId = (string) $this->postJson("/v1/orders/{$this->orderId}/payments", ['cardToken' => 'tok_visa'], ['Idempotency-Key' => (string) Str::uuid7()])
            ->assertAccepted()->json('paymentId');
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

    private function paymentStatus(): string
    {
        return (string) DB::table('payments')->where('id', $this->paymentId)->value('status');
    }

    private function orderStatus(): string
    {
        return (string) DB::table('orders')->where('id', $this->orderId)->value('status');
    }

    /** @return array{int, int} on hand and reserved of LAB-CONSOLE-001 in GRU1 */
    private function stock(): array
    {
        $row = DB::table('stock_items')->where(['sku' => 'LAB-CONSOLE-001', 'fulfillment_center' => 'GRU1'])->first(['on_hand', 'reserved']);

        return [(int) $row?->on_hand, (int) $row?->reserved];
    }

    private function assertOrderPaidMatchesItsContract(): void
    {
        $payload = DB::table('outbox_messages')->where('event_type', 'tucano.commerce.order.paid')->value('payload');
        $event = json_decode((string) $payload, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $event);

        $validator = new Validator();
        $validator->resolver()?->registerFile('urn:tucano:paid', dirname(__DIR__, 5) . '/contracts/events/commerce.order.paid.schema.json');
        self::assertTrue($validator->validate($event->data, 'urn:tucano:paid')->isValid(), (string) json_encode($event->data));
    }
}
