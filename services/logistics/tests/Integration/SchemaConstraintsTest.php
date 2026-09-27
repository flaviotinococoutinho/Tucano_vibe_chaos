<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Seeders\CarrierSeeder;
use Database\Seeders\FulfillmentCenterSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/** The shipment rules that matter most are also enforced by the database. */
#[Group('integration')]
final class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private const string CHECK_VIOLATION = '23514';
    private const string UNIQUE_VIOLATION = '23505';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FulfillmentCenterSeeder::class, CarrierSeeder::class]);
    }

    #[Test]
    public function nothing_leaves_the_warehouse_without_a_label(): void
    {
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertShipment(['status' => 'picked_up']));
    }

    #[Test]
    public function a_paid_order_gets_exactly_one_shipment(): void
    {
        $orderId = Uuid::uuid7()->toString();
        $this->insertShipment(['order_id' => $orderId]);

        $this->assertViolates(self::UNIQUE_VIOLATION, fn() => $this->insertShipment(['order_id' => $orderId]));
    }

    #[Test]
    public function there_is_no_fourth_delivery_attempt(): void
    {
        $shipmentId = $this->insertShipment();

        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertAttempt($shipmentId, 4, 'failed'));
    }

    #[Test]
    public function a_delivery_needs_someone_who_received_it(): void
    {
        $shipmentId = $this->insertShipment();

        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertAttempt($shipmentId, 1, 'delivered'));
    }

    /** @param array<string, mixed> $overrides */
    private function insertShipment(array $overrides = []): string
    {
        return (string) DB::table('shipments')->insertGetId(array_merge([
            'tracking_code' => random_int(1, PHP_INT_MAX),
            'order_id' => Uuid::uuid7()->toString(),
            'status' => 'created',
            'carrier_code' => 'tucano-express',
            'origin' => 'GRU1',
            'recipient_name' => 'Ana Souza',
            'recipient_email' => 'ana@example.com',
            'dest_street' => 'Avenida Paulista',
            'dest_number' => '1000',
            'dest_district' => 'Bela Vista',
            'dest_city' => 'São Paulo',
            'dest_state' => 'SP',
            'dest_postal_code' => '01310100',
            'total_weight_grams' => 1100,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides), 'id');
    }

    private function insertAttempt(string $shipmentId, int $attempt, string $outcome): void
    {
        DB::table('delivery_attempts')->insert([
            'id' => Uuid::uuid7()->toString(),
            'shipment_id' => $shipmentId,
            'attempt_number' => $attempt,
            'outcome' => $outcome,
            'occurred_at' => now(),
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
