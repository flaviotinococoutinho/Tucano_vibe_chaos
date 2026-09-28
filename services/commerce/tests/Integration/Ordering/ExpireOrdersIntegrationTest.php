<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use Commerce\Ordering\Application\Port\Driving\ForExpiringOrders;
use Database\Seeders\FulfillmentCenterSeeder;
use Database\Seeders\StockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\Builders\Addresses;
use Tests\TestCase;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\FrozenClock;

#[Group('integration')]
final class ExpireOrdersIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock('2026-09-27T12:00:00Z');
        $this->app->instance(Clock::class, $this->clock);
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
    }

    #[Test]
    public function an_unpaid_order_expires_and_its_units_go_back_on_sale(): void
    {
        $orderId = $this->placeOrder(quantity: 2);
        self::assertSame(2, $this->reserved());
        $this->clock->moveTo('2026-09-27T12:16:00Z');

        self::assertSame($orderId, $this->app->make(ForExpiringOrders::class)->expireNext()?->toString());

        self::assertSame(['cancelled', 2], [DB::table('orders')->where('id', $orderId)->value('status'), (int) DB::table('orders')->where('id', $orderId)->value('version')]);
        self::assertSame(0, $this->reserved());
        self::assertSame(['released'], DB::table('stock_reservations')->where('order_id', $orderId)->pluck('status')->all());
        self::assertEquals(
            [(object) ['from_status' => null, 'to_status' => 'pending_payment', 'reason' => null], (object) ['from_status' => 'pending_payment', 'to_status' => 'cancelled', 'reason' => 'reservation_expired']],
            DB::table('order_status_transitions')->where('order_id', $orderId)->orderBy('occurred_at')->get(['from_status', 'to_status', 'reason'])->all(),
        );
        self::assertNull($this->app->make(ForExpiringOrders::class)->expireNext());
    }

    #[Test]
    public function the_cancellation_goes_out_as_an_event_in_the_shape_of_its_contract(): void
    {
        $this->placeOrder(quantity: 1);
        $this->clock->moveTo('2026-09-27T12:16:00Z');

        $this->app->make(ForExpiringOrders::class)->expireNext();

        $payload = DB::table('outbox_messages')->where('event_type', 'tucano.commerce.order.cancelled')->value('payload');
        $event = json_decode((string) $payload, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $event);
        self::assertSame(['reason' => 'reservation_expired', 'previousStatus' => 'pending_payment'], ['reason' => $event->data->reason, 'previousStatus' => $event->data->previousStatus]);

        $validator = new Validator();
        $validator->resolver()?->registerFile('urn:tucano:cancelled', dirname(__DIR__, 5) . '/contracts/events/commerce.order.cancelled.schema.json');
        self::assertTrue($validator->validate($event->data, 'urn:tucano:cancelled')->isValid());
    }

    #[Test]
    public function an_order_within_its_window_is_left_alone(): void
    {
        $this->placeOrder(quantity: 1);
        $this->clock->moveTo('2026-09-27T12:14:00Z');

        self::assertNull($this->app->make(ForExpiringOrders::class)->expireNext());
        self::assertSame(1, $this->reserved());
    }

    private function placeOrder(int $quantity): string
    {
        return (string) $this->postJson('/v1/orders', [
            'customer' => ['id' => (string) Str::uuid7(), 'name' => 'Ana Souza', 'email' => 'ana@example.com'],
            'shippingAddress' => Addresses::paulista()->toArray(),
            'items' => [['sku' => 'LAB-CONSOLE-001', 'quantity' => $quantity]],
        ], ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('orderId');
    }

    private function reserved(): int
    {
        return (int) DB::table('stock_items')->where(['sku' => 'LAB-CONSOLE-001', 'fulfillment_center' => 'GRU1'])->value('reserved');
    }
}
