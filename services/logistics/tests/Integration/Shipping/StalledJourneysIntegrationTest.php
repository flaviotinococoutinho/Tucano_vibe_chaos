<?php

declare(strict_types=1);

namespace Tests\Integration\Shipping;

use Database\Seeders\CarrierSeeder;
use Database\Seeders\FulfillmentCenterSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Logistics\Shipping\Application\JourneyResult;
use Logistics\Shipping\Application\Port\Driven\ForFindingStalledJourneys;
use Logistics\Shipping\Application\Port\Driven\ForRecordingJourneyChecks;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\StalledJourney;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\Builders\ShipmentBuilder;
use Tests\TestCase;

/**
 * UC-SHP-13 against PostgreSQL: the rounds of the reconciliation kept as a run
 * of the same answer, and the analytical read over shipments, their history
 * and those runs. The builder dates every step an hour after the one before.
 */
#[Group('integration')]
final class StalledJourneysIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FulfillmentCenterSeeder::class, CarrierSeeder::class]);
    }

    #[Test]
    public function the_same_answer_round_after_round_is_one_run_and_a_new_answer_starts_another(): void
    {
        $shipment = $this->stored(ShipmentBuilder::aShipment()->in(ShipmentStatus::PickedUp));
        $checks = $this->app->make(ForRecordingJourneyChecks::class);
        $id = $shipment->toSnapshot()->reference->id;

        $checks->record($id, JourneyResult::UpToDate, new DateTimeImmutable('2026-09-27T15:00:00Z'));
        $checks->record($id, JourneyResult::UpToDate, new DateTimeImmutable('2026-09-27T15:01:00Z'));
        self::assertSame(['up_to_date', '2026-09-27 15:00:00+00', '2026-09-27 15:01:00+00', 2], $this->checkOf($shipment));

        $checks->record($id, JourneyResult::CarrierUnreachable, new DateTimeImmutable('2026-09-27T15:02:00Z'));
        self::assertSame(['carrier_unreachable', '2026-09-27 15:02:00+00', '2026-09-27 15:02:00+00', 1], $this->checkOf($shipment));
    }

    #[Test]
    public function only_journeys_with_a_carrier_and_no_step_for_too_long_are_stalled_oldest_first(): void
    {
        // The last steps: 13:00 on the 27th, 14:00 on the 27th, 11:30 on the 28th (fresh), and two out of the hands of a carrier.
        $ready = $this->stored(ShipmentBuilder::aShipment()->in(ShipmentStatus::ReadyForPickup));
        $pickedUp = $this->stored(ShipmentBuilder::aShipment()->in(ShipmentStatus::PickedUp));
        $this->stored(ShipmentBuilder::aShipment()->createdAt('2026-09-28T07:30:00Z')->in(ShipmentStatus::OutForDelivery));
        $this->stored(ShipmentBuilder::aShipment()->in(ShipmentStatus::Delivered));
        $this->stored(ShipmentBuilder::aShipment()->create());
        $this->app->make(ForRecordingJourneyChecks::class)->record($ready->toSnapshot()->reference->id, JourneyResult::UnknownToCarrier, new DateTimeImmutable('2026-09-27T13:30:00Z'));

        $stalled = $this->app->make(ForFindingStalledJourneys::class)->quietSince(new DateTimeImmutable('2026-09-28T11:00:00Z'), 10);

        self::assertSame(2, $stalled->total);
        self::assertSame(
            [(string) $ready->toSnapshot()->reference->trackingCode, (string) $pickedUp->toSnapshot()->reference->trackingCode],
            array_map(static fn(StalledJourney $journey): string => $journey->trackingCode, $stalled->shown),
        );
        [$first, $second] = $stalled->shown;
        self::assertSame(['ready_for_pickup', 'tucano-express', JourneyResult::UnknownToCarrier], [$first->status, $first->carrier, $first->lastCheck]);
        self::assertEquals(new DateTimeImmutable('2026-09-27T13:00:00Z'), $first->lastStepAt);
        self::assertEquals(new DateTimeImmutable('2026-09-27T13:30:00Z'), $first->lastCheckSince);
        self::assertNull($second->lastCheck, 'Never claimed by the reconciliation yet.');
    }

    #[Test]
    public function the_limit_cuts_the_list_and_not_the_count(): void
    {
        $oldest = $this->stored(ShipmentBuilder::aShipment()->in(ShipmentStatus::ReadyForPickup));
        $this->stored(ShipmentBuilder::aShipment()->in(ShipmentStatus::InTransit));

        $stalled = $this->app->make(ForFindingStalledJourneys::class)->quietSince(new DateTimeImmutable('2026-09-28T11:00:00Z'), 1);

        self::assertSame(2, $stalled->total);
        self::assertSame([(string) $oldest->toSnapshot()->reference->trackingCode], array_map(static fn(StalledJourney $journey): string => $journey->trackingCode, $stalled->shown));
    }

    #[Test]
    public function a_quiet_operation_reads_as_nothing_stalled(): void
    {
        $stalled = $this->app->make(ForFindingStalledJourneys::class)->quietSince(new DateTimeImmutable('2026-09-28T11:00:00Z'), 10);

        self::assertTrue($stalled->isEmpty());
        self::assertSame([], $stalled->shown);
    }

    private function stored(Shipment $shipment): Shipment
    {
        $this->app->make(ForStoringShipments::class)->add($shipment);

        return $shipment;
    }

    /** @return array{string, string, string, int} */
    private function checkOf(Shipment $shipment): array
    {
        $row = DB::selectOne(
            "SELECT last_result, to_char(result_since AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' AS since, to_char(checked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' AS checked, rounds FROM journey_checks WHERE shipment_id = ?",
            [$shipment->toSnapshot()->reference->id->toString()],
        );
        self::assertInstanceOf(stdClass::class, $row);

        return [(string) $row->last_result, (string) $row->since, (string) $row->checked, (int) $row->rounds];
    }
}
