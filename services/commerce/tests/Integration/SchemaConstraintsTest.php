<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Seeders\FulfillmentCenterSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * The rules that protect money and stock also live in the database, so a bug
 * in the code (or a manual UPDATE) cannot break them.
 */
#[Group('integration')]
final class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private const string CHECK_VIOLATION = '23514';
    private const string UNIQUE_VIOLATION = '23505';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FulfillmentCenterSeeder::class);
    }

    #[Test]
    public function stock_is_never_reserved_beyond_what_is_on_hand(): void
    {
        DB::table('stock_items')->insert(['sku' => 'LAB-CONSOLE-001', 'fulfillment_center' => 'GRU1', 'on_hand' => 5, 'reserved' => 5]);

        $this->assertViolates(self::CHECK_VIOLATION, static fn() => DB::table('stock_items')
            ->where('sku', 'LAB-CONSOLE-001')
            ->update(['reserved' => 6]));
    }

    #[Test]
    public function an_order_has_at_most_one_successful_payment(): void
    {
        $orderId = $this->insertOrder();
        $this->insertPayment($orderId, 'captured');
        $this->insertPayment($orderId, 'failed');

        $this->assertViolates(self::UNIQUE_VIOLATION, fn() => $this->insertPayment($orderId, 'captured'));
    }

    #[Test]
    public function unknown_order_states_are_rejected(): void
    {
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertOrder(['status' => 'shipped_and_cancelled']));
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function misplacedTrackingCodes(): iterable
    {
        yield 'an order that has not shipped' => [['status' => 'paid', 'tracking_code' => 'TX02PWW6JFR5G00']];
        yield 'a code in another shape' => [['status' => 'shipped', 'tracking_code' => 'tx02pww6jfr5g00']];
    }

    /** @param array<string, string> $row */
    #[Test]
    #[DataProvider('misplacedTrackingCodes')]
    public function only_an_order_that_shipped_has_a_tracking_code(array $row): void
    {
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertOrder($row));
    }

    /** @return iterable<string, array{array<string, string|null>}> */
    public static function misplacedCancellationReasons(): iterable
    {
        yield 'a cancelled order that does not say why' => [['status' => 'cancelled', 'cancellation_reason' => null]];
        yield 'a reason on an order that goes on' => [['status' => 'paid', 'cancellation_reason' => 'customer_request']];
        yield 'a reason the domain does not know' => [['status' => 'cancelled', 'cancellation_reason' => 'bored']];
    }

    /** @param array<string, string|null> $row */
    #[Test]
    #[DataProvider('misplacedCancellationReasons')]
    public function only_a_cancelled_order_has_a_reason_and_it_always_has_one(array $row): void
    {
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertOrder($row));
    }

    #[Test]
    public function postal_codes_are_eight_digits(): void
    {
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertOrder(['ship_postal_code' => '01310-10']));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedDivisions(): iterable
    {
        yield 'not a list' => ['{"kind": "state", "code": "SP", "name": "São Paulo"}'];
        yield 'only the state' => ['[{"kind": "state", "code": "SP", "name": "São Paulo"}]'];
        yield 'the municipality first' => ['[{"kind": "municipality", "code": null, "name": "São Paulo"}, {"kind": "state", "code": "SP", "name": "São Paulo"}]'];
        yield 'a state without its UF' => ['[{"kind": "state", "code": null, "name": "São Paulo"}, {"kind": "municipality", "code": null, "name": "São Paulo"}]'];
        yield 'a UF in lowercase' => ['[{"kind": "state", "code": "sp", "name": "São Paulo"}, {"kind": "municipality", "code": null, "name": "São Paulo"}]'];
    }

    #[Test]
    #[DataProvider('malformedDivisions')]
    public function the_divisions_start_with_the_state_and_the_municipality(string $divisions): void
    {
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertOrder(['ship_divisions' => $divisions]));
    }

    #[Test]
    public function the_thoroughfare_and_the_number_are_never_blank(): void
    {
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertOrder(['ship_thoroughfare_type' => '  ']));
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertOrder(['ship_number' => ' ']));
        self::assertNotSame('', $this->insertOrder(['ship_number' => 'KM 500']));
    }

    #[Test]
    public function the_same_idempotency_key_is_stored_once_per_scope(): void
    {
        $key = ['scope' => 'place-order', 'key' => 'a3f1c2', 'request_hash' => hash('sha256', '{}'),
            'response_status' => 201, 'response_body' => '{}', 'expires_at' => now()->addDay()];
        DB::table('idempotency_keys')->insert($key);

        $this->assertViolates(self::UNIQUE_VIOLATION, static fn() => DB::table('idempotency_keys')->insert($key));
    }

    #[Test]
    public function the_database_fills_in_a_uuid_v7_when_the_domain_does_not(): void
    {
        $orderId = $this->insertOrder(['id' => null]);

        // The version nibble is the 13th hex digit: always 7 for UUIDv7.
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $orderId);
    }

    /** @param array<string, mixed> $overrides */
    private function insertOrder(array $overrides = []): string
    {
        $row = array_merge([
            'id' => Uuid::uuid7()->toString(),
            'order_number' => random_int(1, PHP_INT_MAX),
            'customer_id' => Uuid::uuid7()->toString(),
            'customer_name' => 'Ana Souza',
            'customer_email' => 'ana@example.com',
            'status' => 'pending_payment',
            'fulfillment_center' => 'GRU1',
            'ship_thoroughfare_type' => 'Avenida',
            'ship_thoroughfare_name' => 'Paulista',
            'ship_number' => '1000',
            'ship_divisions' => '[{"kind": "state", "code": "SP", "name": "São Paulo"}, {"kind": "municipality", "code": "3550308", "name": "São Paulo"}]',
            'ship_postal_code' => '01310100',
            'total_cents' => 18990,
            'currency' => 'BRL',
            'reservation_expires_at' => now()->addMinutes(15),
            'placed_at' => now(),
            'updated_at' => now(),
        ], $overrides);
        $row = array_filter($row, static fn(mixed $value): bool => $value !== null);

        return (string) DB::table('orders')->insertGetId($row, 'id');
    }

    private function insertPayment(string $orderId, string $status): void
    {
        DB::table('payments')->insert([
            'order_id' => $orderId,
            'status' => $status,
            'amount_cents' => 18990,
            'currency' => 'BRL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertViolates(string $sqlState, callable $statement): void
    {
        try {
            DB::transaction(static fn() => $statement());
        } catch (QueryException $error) {
            self::assertSame($sqlState, $error->errorInfo[0] ?? null, $error->getMessage());

            return;
        }

        self::fail(sprintf('Expected SQLSTATE %s, but the statement succeeded.', $sqlState));
    }
}
