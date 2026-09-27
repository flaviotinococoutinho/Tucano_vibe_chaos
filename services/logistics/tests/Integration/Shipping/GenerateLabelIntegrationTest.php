<?php

declare(strict_types=1);

namespace Tests\Integration\Shipping;

use Database\Seeders\CarrierSeeder;
use Database\Seeders\FulfillmentCenterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Logistics\Shipping\Application\LabelOutcome;
use Logistics\Shipping\Application\Port\Driven\ForStoringLabels;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\Port\Driving\ForGeneratingLabels;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\AssertsContracts;
use Tests\Builders\ShipmentBuilder;
use Tests\Doubles\Shipping\InMemoryLabels;
use Tests\TestCase;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\FrozenClock;

/** UC-SHP-03 against PostgreSQL: the row, its history and the outbox, with the bucket in memory. */
#[Group('integration')]
final class GenerateLabelIntegrationTest extends TestCase
{
    use AssertsContracts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FrozenClock('2026-09-27T12:20:00Z'));
        $this->app->instance(ForStoringLabels::class, new InMemoryLabels());
        $this->seed([FulfillmentCenterSeeder::class, CarrierSeeder::class]);
    }

    #[Test]
    public function the_label_takes_the_shipment_to_ready_for_pickup_with_its_event_in_the_outbox(): void
    {
        $shipment = ShipmentBuilder::aShipment()->create();
        $this->app->make(ForStoringShipments::class)->add($shipment);
        $reference = $shipment->toSnapshot()->reference;

        $outcome = $this->app->make(ForGeneratingLabels::class)->generate($reference->id);

        self::assertSame(LabelOutcome::Attached, $outcome);
        $row = DB::table('shipments')->where('id', $reference->id->toString())->sole();
        self::assertSame(['ready_for_pickup', "labels/{$reference->trackingCode}.zpl"], [$row->status, $row->label_object_key]);
        self::assertSame(
            [null, 'created'],
            DB::table('shipment_transitions')->where(['shipment_id' => $reference->id->toString(), 'to_status' => 'ready_for_pickup'])->get(['reason', 'from_status'])->map(static fn(stdClass $line): array => [$line->reason, $line->from_status])->sole(),
        );
        $event = json_decode((string) DB::table('outbox_messages')->where('event_type', 'tucano.logistics.shipment.ready_for_pickup')->value('payload'), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $event);
        self::assertMatchesContract('cloudevent.schema.json', $event);
        self::assertMatchesContract('logistics.shipment.ready_for_pickup.schema.json', $event->data);
    }

    #[Test]
    public function the_database_keeps_a_shipment_from_waiting_for_pickup_without_a_label(): void
    {
        $shipment = ShipmentBuilder::aShipment()->create();
        $this->app->make(ForStoringShipments::class)->add($shipment);

        $this->expectExceptionMessage('label_before_pickup');

        DB::table('shipments')->where('id', $shipment->toSnapshot()->reference->id->toString())->update(['status' => 'ready_for_pickup']);
    }
}
