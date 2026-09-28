<?php

declare(strict_types=1);

namespace Tests\Integration\Shipping;

use Database\Seeders\CarrierSeeder;
use Database\Seeders\FulfillmentCenterSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Logistics\Shipping\Application\CarrierEvent;
use Logistics\Shipping\Application\CarrierJourney;
use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\JourneyResult;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\Port\Driven\ForTrackingPickups;
use Logistics\Shipping\Application\Port\Driving\ForReconcilingJourneys;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Builders\ShipmentBuilder;
use Tests\Doubles\Shipping\ScriptedTracking;
use Tests\TestCase;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\FrozenClock;

/** UC-SHP-12 against PostgreSQL: the claim with SKIP LOCKED, and the missing steps landing like webhooks. */
#[Group('integration')]
final class JourneyReconciliationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private FrozenClock $clock;

    private ScriptedTracking $carrier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock('2026-09-27T15:00:00Z');
        $this->carrier = new ScriptedTracking();
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(ForTrackingPickups::class, $this->carrier);
        $this->seed([FulfillmentCenterSeeder::class, CarrierSeeder::class]);
    }

    #[Test]
    public function the_steps_lost_on_the_way_land_from_the_history_of_the_carrier(): void
    {
        $shipment = $this->stored(ShipmentStatus::ReadyForPickup);
        $pickedUp = CarrierEvent::pickedUp($this->report($shipment, 'evt_1', '14:55'));
        $pickedUp->applyTo($this->app->make(CarrierJourney::class));
        $this->quietSince($shipment, '14:58');
        $this->carrier->knows(
            $shipment->toSnapshot()->reference->id,
            $pickedUp,
            CarrierEvent::outForDelivery($this->report($shipment, 'evt_2', '14:56')),
            CarrierEvent::delivered($this->report($shipment, 'evt_3', '14:57'), ProofOfDelivery::of('Carlos Lima', '***.456.789-**')),
        );

        $reconciled = $this->app->make(ForReconcilingJourneys::class)->reconcileNext();

        self::assertSame([JourneyResult::CaughtUp, 2], [$reconciled?->result, $reconciled?->applied]);
        $id = $shipment->toSnapshot()->reference->id->toString();
        self::assertSame('delivered', DB::table('shipments')->where('id', $id)->value('status'));
        self::assertSame(['picked_up', 'out_for_delivery', 'delivered'], DB::table('shipment_transitions')->where('shipment_id', $id)->whereNotIn('to_status', ['created', 'ready_for_pickup'])->orderBy('occurred_at')->pluck('to_status')->all());
        self::assertSame(3, DB::table('inbox_messages')->count());
        self::assertSame('Carlos Lima', DB::table('delivery_attempts')->where('shipment_id', $id)->value('receiver_name'));
    }

    #[Test]
    public function the_quietest_shipment_goes_first_and_each_claim_touches_it(): void
    {
        $older = $this->stored(ShipmentStatus::PickedUp);
        $newer = $this->stored(ShipmentStatus::OutForDelivery);
        $this->quietSince($older, '14:50');
        $this->quietSince($newer, '14:55');
        $shipments = $this->app->make(ForStoringShipments::class);
        $now = $this->clock->now();
        $quietSince = $now->modify('-60 seconds');

        $claimed = [$shipments->claimQuiet($quietSince, $now), $shipments->claimQuiet($quietSince, $now), $shipments->claimQuiet($quietSince, $now)];

        self::assertSame(
            [(string) $older->toSnapshot()->reference->trackingCode, (string) $newer->toSnapshot()->reference->trackingCode, null],
            array_map(static fn($reference): ?string => $reference === null ? null : (string) $reference->trackingCode, $claimed),
        );
        self::assertSame(2, DB::table('shipments')->where('updated_at', $now->format(DATE_RFC3339_EXTENDED))->count());
    }

    private function stored(ShipmentStatus $status): Shipment
    {
        $shipment = ShipmentBuilder::aShipment()->in($status);
        $this->app->make(ForStoringShipments::class)->add($shipment);

        return $shipment;
    }

    private function quietSince(Shipment $shipment, string $time): void
    {
        DB::table('shipments')->where('id', $shipment->toSnapshot()->reference->id->toString())->update(['updated_at' => '2026-09-27T' . $time . ':00Z']);
    }

    private function report(Shipment $shipment, string $eventId, string $time): CarrierReport
    {
        return CarrierReport::of($eventId, $shipment->toSnapshot()->reference->trackingCode, new DateTimeImmutable('2026-09-27T' . $time . ':00Z'));
    }
}
