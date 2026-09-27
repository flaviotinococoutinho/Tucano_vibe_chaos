<?php

declare(strict_types=1);

namespace Tests\Integration\Shipping;

use Database\Seeders\CarrierSeeder;
use Database\Seeders\FulfillmentCenterSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Domain\Error\ShipmentChangedMeanwhile;
use Logistics\Shipping\Domain\Shipment\CancellationReason;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Builders\ShipmentBuilder;
use Tests\TestCase;

#[Group('integration')]
final class PostgresShipmentsTest extends TestCase
{
    use RefreshDatabase;

    private ForStoringShipments $shipments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FulfillmentCenterSeeder::class, CarrierSeeder::class]);
        $this->shipments = $this->app->make(ForStoringShipments::class);
    }

    /** @return iterable<string, array{ShipmentStatus}> */
    public static function statuses(): iterable
    {
        foreach (ShipmentStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    #[Test]
    #[DataProvider('statuses')]
    public function a_shipment_comes_back_as_it_was_stored(ShipmentStatus $status): void
    {
        $shipment = ShipmentBuilder::aShipment()
            ->withParcels(ShipmentBuilder::parcel(2200, 240, 170, 80), ShipmentBuilder::parcel(350, 120, 90, 100))
            ->in($status);

        $this->shipments->add($shipment);

        self::assertEquals($shipment->toSnapshot(), $this->stored($shipment)->toSnapshot());
    }

    #[Test]
    public function a_refusal_at_the_door_survives_the_round_trip_so_the_return_is_still_allowed(): void
    {
        $shipment = ShipmentBuilder::aShipment()->refused();
        $this->shipments->add($shipment);

        $stored = $this->stored($shipment);
        $stored->returnToSender(new DateTimeImmutable('2026-09-29T09:00:00Z'));
        $this->shipments->save($stored);

        self::assertSame(['returning', 1], [DB::table('shipments')->value('status'), (int) DB::table('shipments')->value('delivery_attempts')]);
    }

    #[Test]
    public function saving_appends_to_the_history(): void
    {
        $shipment = ShipmentBuilder::aShipment()->create();
        $this->shipments->add($shipment);

        $stored = $this->stored($shipment);
        $stored->cancel(CancellationReason::OrderCancelled, new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $this->shipments->save($stored);

        self::assertSame(['cancelled', 2], [DB::table('shipments')->value('status'), (int) DB::table('shipments')->value('version')]);
        self::assertEquals(
            [(object) ['to_status' => 'created', 'reason' => null], (object) ['to_status' => 'cancelled', 'reason' => 'order_cancelled']],
            DB::table('shipment_transitions')->orderBy('occurred_at')->get(['to_status', 'reason'])->all(),
        );
        self::assertEquals(new DateTimeImmutable('2026-09-27T13:00:00Z'), new DateTimeImmutable((string) DB::table('shipments')->value('updated_at')));
    }

    #[Test]
    public function a_stale_copy_cannot_overwrite_a_newer_one(): void
    {
        $shipment = ShipmentBuilder::aShipment()->in(ShipmentStatus::ReadyForPickup);
        $this->shipments->add($shipment);
        $first = $this->stored($shipment);
        $second = $this->stored($shipment);
        $first->recordPickup(new DateTimeImmutable('2026-09-27T16:00:00Z'));
        $this->shipments->save($first);
        $second->cancel(CancellationReason::OrderCancelled, new DateTimeImmutable('2026-09-27T16:00:01Z'));

        $this->expectException(ShipmentChangedMeanwhile::class);

        $this->shipments->save($second);
    }

    #[Test]
    public function an_order_without_a_shipment_has_none(): void
    {
        self::assertNull($this->shipments->forOrder(OrderId::generate()));
    }

    private function stored(Shipment $shipment): Shipment
    {
        $stored = $this->shipments->forOrder($shipment->toSnapshot()->reference->orderId);
        self::assertNotNull($stored);

        return $stored;
    }
}
