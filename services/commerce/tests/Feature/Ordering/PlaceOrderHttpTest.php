<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use Database\Seeders\FulfillmentCenterSeeder;
use Database\Seeders\StockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

#[Group('integration')]
final class PlaceOrderHttpTest extends TestCase
{
    use RefreshDatabase;

    private const string CUSTOMER = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FulfillmentCenterSeeder::class, StockSeeder::class]);
        DB::table('product_snapshots')->insert([
            self::snapshot('BOOK-DDD-001', 'Domain-Driven Design', 18990, 'active'),
            self::snapshot('ELEC-MON-027', 'Monitor de 27 polegadas', 189990, 'active'),
            self::snapshot('ELEC-MP3-001', 'Tocador de MP3', 19990, 'discontinued'),
        ]);
    }

    #[Test]
    public function a_placed_order_answers_201_with_where_to_find_it(): void
    {
        $response = $this->place('key-1', ['BOOK-DDD-001' => 2])
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertJsonPath('status', 'pending_payment')
            ->assertJsonPath('fulfillmentCenter', 'BHZ1')
            ->assertJsonPath('total', ['amount' => 37980, 'currency' => 'BRL'])
            ->assertJsonPath('lines.0.unitPrice', ['amount' => 18990, 'currency' => 'BRL']);

        $orderId = (string) $response->json('orderId');
        $response->assertHeader('Location', 'orders/' . $orderId);
        self::assertSame(2, (int) DB::table('stock_items')->where(['sku' => 'BOOK-DDD-001', 'fulfillment_center' => 'BHZ1'])->value('reserved'));

        // The deferred foreign key of stock_reservations is satisfied: the order exists.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->getJson('/v1/orders/' . $orderId)->assertOk()->assertExactJson($response->json());
    }

    #[Test]
    public function the_event_goes_to_the_outbox_in_the_shape_of_the_contracts(): void
    {
        $orderId = (string) $this->place('key-1', ['BOOK-DDD-001' => 1], ['X-Correlation-Id' => 'req-7#1'])->json('orderId');

        $message = DB::table('outbox_messages')->sole();
        self::assertSame(['commerce.orders.v2', $orderId, 'tucano.commerce.order.placed'], [$message->topic, $message->message_key, $message->event_type]);

        $event = json_decode((string) $message->payload, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $event);
        self::assertSame('req-7#1', $event->correlationid);
        self::assertMatchesContract('cloudevent.schema.json', $event);
        self::assertMatchesContract('commerce.order.placed.schema.json', $event->data);
    }

    #[Test]
    public function the_same_request_twice_places_one_order(): void
    {
        $first = $this->place('key-1', ['BOOK-DDD-001' => 1])->assertCreated();

        $this->place('key-1', ['BOOK-DDD-001' => 1])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertExactJson($first->json());

        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(1, DB::table('outbox_messages')->count());
    }

    #[Test]
    public function a_key_reused_for_another_order_is_refused(): void
    {
        $this->place('key-1', ['BOOK-DDD-001' => 1])->assertCreated();

        $this->place('key-1', ['BOOK-DDD-001' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('detail', 'Idempotency key key-1 was already used for a different request.');
    }

    #[Test]
    public function an_order_needs_an_idempotency_key(): void
    {
        $this->postJson('/v1/orders', self::body(['BOOK-DDD-001' => 1]))
            ->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    #[Test]
    public function products_the_catalog_does_not_sell_are_refused(): void
    {
        $this->place('key-1', ['BOOK-NONE-001' => 1])->assertConflict()->assertJsonPath('detail', 'BOOK-NONE-001 is not in the catalog.');
        $this->place('key-2', ['ELEC-MP3-001' => 1])->assertConflict()->assertJsonPath('detail', 'ELEC-MP3-001 is no longer sold.');
    }

    #[Test]
    public function without_stock_the_order_is_refused_and_nothing_stays_held(): void
    {
        $this->place('key-1', ['BOOK-DDD-001' => 1, 'ELEC-MON-027' => 10])->assertCreated();

        $this->place('key-2', ['BOOK-DDD-001' => 1, 'ELEC-MON-027' => 3])
            ->assertConflict()
            ->assertJsonPath('detail', 'Not enough stock: BHZ1 is short of ELEC-MON-027; GRU1 is short of ELEC-MON-027.');

        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(11, (int) DB::table('stock_items')->sum('reserved'));
    }

    #[Test]
    public function a_malformed_order_lists_what_is_wrong(): void
    {
        $this->postJson('/v1/orders', ['customer' => ['id' => 'not-a-uuid']], ['Idempotency-Key' => 'key-1'])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['customer.id', 'customer.name', 'shippingAddress', 'items']]);
    }

    #[Test]
    public function on_a_highway_the_number_is_the_kilometre(): void
    {
        $body = self::body(['BOOK-DDD-001' => 1]);
        $body['shippingAddress']['thoroughfare'] = ['type' => 'Rodovia', 'name' => 'Fernão Dias'];
        $body['shippingAddress']['number'] = 'KM 500';
        $body['shippingAddress']['divisions'] = [
            ['kind' => 'state', 'code' => 'MG', 'name' => 'Minas Gerais'],
            ['kind' => 'municipality', 'code' => '3106705', 'name' => 'Betim'],
        ];

        $this->postJson('/v1/orders', $body, ['Idempotency-Key' => 'key-1'])
            ->assertCreated()
            ->assertJsonPath('shippingAddress.thoroughfare', ['type' => 'Rodovia', 'name' => 'Fernão Dias'])
            ->assertJsonPath('shippingAddress.number', 'KM 500');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function undeliverableAddresses(): iterable
    {
        $bh = ['kind' => 'municipality', 'code' => '3106200', 'name' => 'Belo Horizonte'];

        yield 'the number as a JSON number' => [['number' => 1200], 'shippingAddress.number'];
        yield 'a neighborhood above the municipality' => [
            ['divisions' => [['kind' => 'state', 'code' => 'MG', 'name' => 'Minas Gerais'], ['kind' => 'neighborhood', 'name' => 'Centro'], $bh]],
            'The divisions of an address start with its state and its municipality.',
        ];
        yield 'a municipality of São Paulo in Minas Gerais' => [
            ['divisions' => [['kind' => 'state', 'code' => 'MG', 'name' => 'Minas Gerais'], ['kind' => 'municipality', 'code' => '3550308', 'name' => 'São Paulo']]],
            'The IBGE geocode 3550308 of São Paulo is not inside Minas Gerais (31).',
        ];
        yield 'a kind of division nobody knows' => [
            ['divisions' => [['kind' => 'state', 'code' => 'MG', 'name' => 'Minas Gerais'], $bh, ['kind' => 'county', 'name' => 'Centro']]],
            'shippingAddress.divisions.2.kind',
        ];
    }

    /** @param array<string, mixed> $change */
    #[Test]
    #[DataProvider('undeliverableAddresses')]
    public function an_address_nobody_could_deliver_to_is_refused(array $change, string $why): void
    {
        $body = self::body(['BOOK-DDD-001' => 1]);
        $body['shippingAddress'] = [...$body['shippingAddress'], ...$change];

        $response = $this->postJson('/v1/orders', $body, ['Idempotency-Key' => 'key-1'])->assertUnprocessable();

        self::assertStringContainsString($why, (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        self::assertSame(0, DB::table('orders')->count());
    }

    #[Test]
    public function an_order_that_does_not_exist_is_not_found(): void
    {
        $this->getJson('/v1/orders/' . Uuid::uuid7()->toString())->assertNotFound();
        $this->getJson('/v1/orders/not-an-id')->assertNotFound();
    }

    /**
     * @param non-empty-array<string, int> $items SKU => quantity
     * @param array<string, string> $headers
     *
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function place(string $key, array $items, array $headers = []): TestResponse
    {
        return $this->postJson('/v1/orders', self::body($items), ['Idempotency-Key' => $key, ...$headers]);
    }

    /**
     * @param non-empty-array<string, int> $items SKU => quantity
     *
     * @return array{customer: array<string, string>, shippingAddress: array<string, mixed>, items: list<array{sku: string, quantity: int}>}
     */
    private static function body(array $items): array
    {
        $lines = [];
        foreach ($items as $sku => $quantity) {
            $lines[] = ['sku' => $sku, 'quantity' => $quantity];
        }

        return [
            'customer' => ['id' => self::CUSTOMER, 'name' => 'Ana Souza', 'email' => 'ana@example.com'],
            'shippingAddress' => [
                'thoroughfare' => ['type' => 'Rua', 'name' => 'da Bahia'],
                'number' => '1200',
                'divisions' => [
                    ['kind' => 'state', 'code' => 'MG', 'name' => 'Minas Gerais'],
                    ['kind' => 'municipality', 'code' => '3106200', 'name' => 'Belo Horizonte'],
                    ['kind' => 'neighborhood', 'name' => 'Centro'],
                ],
                'postalCode' => '30160-011',
            ],
            'items' => $lines,
        ];
    }

    /** @return array<string, mixed> */
    private static function snapshot(string $sku, string $name, int $cents, string $status): array
    {
        return [
            'product_id' => Uuid::uuid7()->toString(),
            'sku' => $sku,
            'name' => $name,
            'price_cents' => $cents,
            'currency' => 'BRL',
            'status' => $status,
            'catalog_version' => 1,
        ];
    }

    private static function assertMatchesContract(string $schema, mixed $data): void
    {
        $validator = new Validator();
        $validator->resolver()?->registerFile('urn:tucano:' . $schema, dirname(__DIR__, 5) . '/contracts/events/' . $schema);
        $result = $validator->validate($data, 'urn:tucano:' . $schema);

        self::assertTrue($result->isValid(), json_encode(
            $result->error() === null ? [] : (new ErrorFormatter())->format($result->error()),
            JSON_THROW_ON_ERROR,
        ));
    }
}
