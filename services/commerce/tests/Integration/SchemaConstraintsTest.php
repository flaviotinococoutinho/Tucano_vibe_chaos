<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Seeders\FulfillmentCenterSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    #[Test]
    public function postal_codes_are_eight_digits(): void
    {
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertOrder(['ship_postal_code' => '01310-10']));
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
            'ship_street' => 'Avenida Paulista',
            'ship_number' => '1000',
            'ship_district' => 'Bela Vista',
            'ship_city' => 'São Paulo',
            'ship_state' => 'SP',
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
