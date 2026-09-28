<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Seeders\CarrierSeeder;
use Database\Seeders\FulfillmentCenterSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{string}> */
    public static function malformedDivisions(): iterable
    {
        yield 'not a list' => ['{"kind": "state", "code": "SP", "name": "São Paulo"}'];
        yield 'only the state' => ['[{"kind": "state", "code": "SP", "name": "São Paulo"}]'];
        yield 'the municipality first' => ['[{"kind": "municipality", "code": null, "name": "São Paulo"}, {"kind": "state", "code": "SP", "name": "São Paulo"}]'];
        yield 'a state without its UF' => ['[{"kind": "state", "code": null, "name": "São Paulo"}, {"kind": "municipality", "code": null, "name": "São Paulo"}]'];
    }

    #[Test]
    #[DataProvider('malformedDivisions')]
    public function the_destination_starts_with_the_state_and_the_municipality(string $divisions): void
    {
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertShipment(['dest_divisions' => $divisions]));
    }

    #[Test]
    public function the_thoroughfare_and_the_number_are_never_blank(): void
    {
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertShipment(['dest_thoroughfare_name' => ' ']));
        $this->assertViolates(self::CHECK_VIOLATION, fn() => $this->insertShipment(['dest_number' => '']));
        self::assertNotSame('', $this->insertShipment(['dest_number' => 'S/N']));
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
            'dest_thoroughfare_type' => 'Avenida',
            'dest_thoroughfare_name' => 'Paulista',
            'dest_number' => '1000',
            'dest_divisions' => '[{"kind": "state", "code": "SP", "name": "São Paulo"}, {"kind": "municipality", "code": "3550308", "name": "São Paulo"}]',
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
