<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Database\Seeders\FulfillmentCenterSeeder;
use Database\Seeders\StockSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Builders\Addresses;
use Tests\Doubles\Payments\FakeCardGateway;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\FrozenClock;

/**
 * The start of every settlement story: two consoles ordered and paid through the
 * API at noon, with the provider played by a fake that takes the charge.
 */
trait PaysForAnOrder
{
    private FrozenClock $clock;

    private FakeCardGateway $gateway;

    private string $orderId;

    private string $paymentId;

    private function placeAndPayAnOrder(): void
    {
        $this->clock = new FrozenClock('2026-09-27T12:00:00Z');
        $this->app->instance(Clock::class, $this->clock);
        $this->gateway = new FakeCardGateway();
        $this->app->instance(ForChargingCards::class, $this->gateway);
        $this->seed([FulfillmentCenterSeeder::class, StockSeeder::class]);
        DB::table('product_snapshots')->insert([
            'product_id' => (string) Str::uuid7(),
            'sku' => 'LAB-CONSOLE-001',
            'name' => 'Console portátil edição limitada',
            'price_cents' => 299990,
            'currency' => 'BRL',
            'status' => 'active',
            'store' => 'bemtevi',
            'catalog_version' => 1,
        ]);
        $this->orderId = (string) $this->postJson('/v1/orders', [
            'store' => 'bemtevi',
            'customer' => ['id' => (string) Str::uuid7(), 'name' => 'Ana Souza', 'email' => 'ana@example.com'],
            'shippingAddress' => Addresses::paulista()->toArray(),
            'items' => [['sku' => 'LAB-CONSOLE-001', 'quantity' => 2]],
        ], ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('orderId');
        $this->paymentId = (string) $this->postJson("/v1/orders/{$this->orderId}/payments", ['cardToken' => 'tok_visa'], ['Idempotency-Key' => (string) Str::uuid7()])
            ->assertAccepted()->json('paymentId');
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
}
