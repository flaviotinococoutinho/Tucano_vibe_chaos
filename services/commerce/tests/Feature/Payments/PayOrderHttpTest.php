<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Database\Seeders\FulfillmentCenterSeeder;
use Database\Seeders\StockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Builders\Addresses;
use Tests\Doubles\Payments\FakeCardGateway;
use Tests\TestCase;

#[Group('integration')]
final class PayOrderHttpTest extends TestCase
{
    use RefreshDatabase;

    private FakeCardGateway $gateway;

    private string $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeCardGateway();
        $this->app->instance(ForChargingCards::class, $this->gateway);
        $this->seed([FulfillmentCenterSeeder::class, StockSeeder::class]);
        DB::table('product_snapshots')->insert([
            'product_id' => (string) Str::uuid7(),
            'sku' => 'BOOK-DDD-001',
            'name' => 'Domain-Driven Design',
            'price_cents' => 18990,
            'currency' => 'BRL',
            'status' => 'active',
            'catalog_version' => 1,
        ]);
        $this->orderId = (string) $this->postJson('/v1/orders', [
            'customer' => ['id' => (string) Str::uuid7(), 'name' => 'Ana Souza', 'email' => 'ana@example.com'],
            'shippingAddress' => Addresses::paulista()->toArray(),
            'items' => [['sku' => 'BOOK-DDD-001', 'quantity' => 1]],
        ], ['Idempotency-Key' => (string) Str::uuid7()])->json('orderId');
    }

    #[Test]
    public function a_payment_goes_to_the_provider_and_answers_202(): void
    {
        $this->pay('key-1')
            ->assertAccepted()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertJsonPath('orderId', $this->orderId)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('amount', ['amount' => 18990, 'currency' => 'BRL']);

        self::assertCount(1, $this->gateway->charged);
        self::assertNotNull(DB::table('payments')->where('order_id', $this->orderId)->value('provider_charge_id'));
    }

    #[Test]
    public function the_same_request_again_is_a_replay(): void
    {
        $first = $this->pay('key-1');

        $this->pay('key-1')->assertAccepted()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('paymentId', $first->json('paymentId'));
        self::assertCount(1, $this->gateway->charged);
    }

    #[Test]
    public function an_open_circuit_answers_503_with_when_to_try_again(): void
    {
        $this->gateway->goesDownFor(17);

        $this->pay('key-1')
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '17')
            ->assertJsonPath('detail', 'The payment provider is unavailable; try again in 17 s.');
        self::assertSame(0, DB::table('payments')->count());
    }

    #[Test]
    public function only_an_order_waiting_for_payment_can_be_paid(): void
    {
        DB::table('orders')->where('id', $this->orderId)->update(['status' => 'cancelled', 'cancellation_reason' => 'customer_request']);

        $this->pay('key-1')
            ->assertConflict()
            ->assertJsonPath('type', 'https://github.com/flaviotinococoutinho/chaos_playground/blob/develop/contracts/http/problems.md#order-not-payable')
            ->assertJsonPath('detail', sprintf('Order %s cannot be paid: it is cancelled.', $this->orderId));
        $this->postJson('/v1/orders/' . Str::uuid7() . '/payments', ['cardToken' => 'tok_visa'], ['Idempotency-Key' => 'key-2'])->assertNotFound();
    }

    #[Test]
    public function a_card_number_sent_as_a_token_is_refused_without_being_repeated(): void
    {
        $response = $this->postJson('/v1/orders/' . $this->orderId . '/payments', ['cardToken' => '4111 1111 1111 1111'], ['Idempotency-Key' => 'key-1']);

        $response->assertUnprocessable()->assertJsonPath('detail', 'The card token is not in the format of the payment provider.');
        // PCI DSS: a card number that reaches Tucano by mistake must not travel back, nor into a log.
        self::assertStringNotContainsString('4111', (string) $response->getContent());
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function pay(string $key): TestResponse
    {
        return $this->postJson('/v1/orders/' . $this->orderId . '/payments', ['cardToken' => 'tok_visa'], ['Idempotency-Key' => $key]);
    }
}
