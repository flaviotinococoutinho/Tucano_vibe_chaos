<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Logistics\Shipping\Application\CarrierEvent;
use Logistics\Shipping\Application\CarrierJourney;
use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\JourneyResult;
use Logistics\Shipping\Application\ShipmentProgress;
use Logistics\Shipping\Application\UseCase\DispatchForDelivery;
use Logistics\Shipping\Application\UseCase\ReconcileJourneys;
use Logistics\Shipping\Application\UseCase\RecordDeliveryOutcome;
use Logistics\Shipping\Application\UseCase\RecordHubScan;
use Logistics\Shipping\Application\UseCase\RecordPickup;
use Logistics\Shipping\Application\UseCase\ReturnToSender;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Transition\Hub;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;
use Tests\Doubles\Shared\DirectTransactions;
use Tests\Doubles\Shared\InMemoryInbox;
use Tests\Doubles\Shared\RecordedEvents;
use Tests\Doubles\Shipping\InMemoryShipments;
use Tests\Doubles\Shipping\RecordedJourneyChecks;
use Tests\Doubles\Shipping\ScriptedTracking;
use Tucano\SharedKernel\Time\FrozenClock;

final class ReconcileJourneysTest extends TestCase
{
    private FrozenClock $clock;

    private InMemoryShipments $shipments;

    private RecordedEvents $events;

    private ScriptedTracking $carrier;

    private CarrierJourney $journey;

    private RecordedJourneyChecks $checks;

    private ReconcileJourneys $reconciliation;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-09-27T15:00:00Z');
        $this->shipments = new InMemoryShipments();
        $this->events = new RecordedEvents();
        $this->carrier = new ScriptedTracking();
        $progress = new ShipmentProgress(new DirectTransactions(), new InMemoryInbox(), $this->shipments, $this->events);
        $this->journey = new CarrierJourney(new RecordPickup($progress), new RecordHubScan($progress), new DispatchForDelivery($progress), new RecordDeliveryOutcome($progress), new ReturnToSender($progress));
        $this->checks = new RecordedJourneyChecks();
        $this->reconciliation = new ReconcileJourneys($this->shipments, $this->carrier, $this->journey, $this->checks, $this->clock, 60);
    }

    #[Test]
    public function a_shipment_that_lost_a_webhook_catches_up_in_order(): void
    {
        $shipment = $this->readyForPickup();
        $pickedUp = CarrierEvent::pickedUp($this->report($shipment, 'evt_1', '-5 minutes'));
        // The webhook of the pickup came in; the one of the dispatch was dropped, and the delivery was refused as early.
        $pickedUp->applyTo($this->journey);
        $this->quietSince($shipment, '-2 minutes');
        $this->carrier->knows(
            $shipment->toSnapshot()->reference->id,
            $pickedUp,
            CarrierEvent::outForDelivery($this->report($shipment, 'evt_2', '-4 minutes')),
            CarrierEvent::delivered($this->report($shipment, 'evt_3', '-3 minutes'), ProofOfDelivery::of('Carlos Lima', '***.456.789-**')),
        );

        $reconciled = $this->reconciliation->reconcileNext();

        self::assertSame([JourneyResult::CaughtUp, 2], [$reconciled?->result, $reconciled?->applied]);
        self::assertSame(ShipmentStatus::Delivered, $shipment->toSnapshot()->status);
        self::assertSame(
            ['tucano.logistics.shipment.picked_up', 'tucano.logistics.shipment.out_for_delivery', 'tucano.logistics.shipment.delivered'],
            $this->events->types(),
        );
        self::assertSame([[$shipment->toSnapshot()->reference->id->toString(), JourneyResult::CaughtUp]], $this->checks->rounds, 'Every round is recorded for the watch of stalled journeys.');
    }

    #[Test]
    public function a_shipment_the_carrier_has_no_pickup_for_is_unknown_to_it(): void
    {
        $shipment = $this->readyForPickup();
        $this->quietSince($shipment, '-2 minutes');

        self::assertSame(JourneyResult::UnknownToCarrier, $this->reconciliation->reconcileNext()?->result);
        self::assertSame(JourneyResult::UnknownToCarrier, $this->checks->rounds[0][1]);
    }

    #[Test]
    public function a_hub_scan_lost_before_the_dispatch_is_old_news_and_the_catch_up_goes_on(): void
    {
        $shipment = $this->readyForPickup();
        $pickedUp = CarrierEvent::pickedUp($this->report($shipment, 'evt_1', '-9 minutes'));
        $originHub = CarrierEvent::hubScanned($this->report($shipment, 'evt_2', '-8 minutes'), Hub::named('Hub Cajamar (SP)'));
        $dispatched = CarrierEvent::outForDelivery($this->report($shipment, 'evt_4', '-6 minutes'));
        // The scan at the destination hub was dropped; the dispatch came in anyway, from in_transit.
        foreach ([$pickedUp, $originHub, $dispatched] as $webhook) {
            $webhook->applyTo($this->journey);
        }
        $this->quietSince($shipment, '-2 minutes');
        $this->carrier->knows(
            $shipment->toSnapshot()->reference->id,
            $pickedUp,
            $originHub,
            CarrierEvent::hubScanned($this->report($shipment, 'evt_3', '-7 minutes'), Hub::named('Hub Contagem (MG)')),
            $dispatched,
            CarrierEvent::delivered($this->report($shipment, 'evt_5', '-5 minutes'), ProofOfDelivery::of('Carlos Lima', '***.456.789-**')),
        );

        $reconciled = $this->reconciliation->reconcileNext();

        self::assertSame([JourneyResult::CaughtUp, 1], [$reconciled?->result, $reconciled?->applied]);
        self::assertSame(ShipmentStatus::Delivered, $shipment->toSnapshot()->status);
    }

    #[Test]
    public function a_journey_that_is_only_slow_is_up_to_date_and_left_alone_for_a_while(): void
    {
        $shipment = $this->readyForPickup();
        $pickedUp = CarrierEvent::pickedUp($this->report($shipment, 'evt_1', '-5 minutes'));
        $pickedUp->applyTo($this->journey);
        $this->quietSince($shipment, '-2 minutes');
        $this->carrier->knows($shipment->toSnapshot()->reference->id, $pickedUp);

        self::assertSame(JourneyResult::UpToDate, $this->reconciliation->reconcileNext()?->result);
        self::assertNull($this->reconciliation->reconcileNext(), 'The claim touched it: it is not quiet any more.');
    }

    #[Test]
    public function nothing_is_due_before_the_quiet_period(): void
    {
        $shipment = $this->readyForPickup();
        $this->quietSince($shipment, '-30 seconds');

        self::assertNull($this->reconciliation->reconcileNext());
    }

    #[Test]
    public function a_shipment_the_carrier_does_not_move_is_never_claimed(): void
    {
        $created = ShipmentBuilder::aShipment()->create();
        $delivered = ShipmentBuilder::aShipment()->in(ShipmentStatus::Delivered);
        $this->shipments->add($created);
        $this->shipments->add($delivered);
        $this->quietSince($created, '-1 hour');
        $this->quietSince($delivered, '-1 hour');

        self::assertNull($this->reconciliation->reconcileNext());
    }

    #[Test]
    public function a_carrier_that_does_not_answer_leaves_the_shipment_for_the_next_quiet_period(): void
    {
        $shipment = $this->readyForPickup();
        $this->quietSince($shipment, '-2 minutes');
        $this->carrier->goesDown();

        self::assertSame(JourneyResult::CarrierUnreachable, $this->reconciliation->reconcileNext()?->result);
        self::assertNull($this->reconciliation->reconcileNext());
        self::assertSame(ShipmentStatus::ReadyForPickup, $shipment->toSnapshot()->status);
    }

    #[Test]
    public function a_step_the_machine_refuses_stops_the_catch_up_where_it_is(): void
    {
        $shipment = $this->readyForPickup();
        $this->quietSince($shipment, '-2 minutes');
        // A history this carrier should never have: delivered straight after the pickup.
        $this->carrier->knows(
            $shipment->toSnapshot()->reference->id,
            CarrierEvent::pickedUp($this->report($shipment, 'evt_1', '-5 minutes')),
            CarrierEvent::delivered($this->report($shipment, 'evt_2', '-4 minutes'), ProofOfDelivery::of('Carlos Lima', '***.456.789-**')),
        );

        $reconciled = $this->reconciliation->reconcileNext();

        self::assertSame([JourneyResult::Stopped, 1], [$reconciled?->result, $reconciled?->applied]);
        self::assertNotNull($reconciled?->refusal);
        self::assertSame(ShipmentStatus::PickedUp, $shipment->toSnapshot()->status);
    }

    private function readyForPickup(): Shipment
    {
        $shipment = ShipmentBuilder::aShipment()->in(ShipmentStatus::ReadyForPickup);
        // What the builder went through is not news for this test.
        $shipment->releaseEvents();
        $this->shipments->add($shipment);

        return $shipment;
    }

    private function quietSince(Shipment $shipment, string $ago): void
    {
        $this->shipments->touch($shipment, $this->clock->now()->modify($ago));
    }

    private function report(Shipment $shipment, string $eventId, string $ago): CarrierReport
    {
        return CarrierReport::of($eventId, $shipment->toSnapshot()->reference->trackingCode, $this->clock->now()->modify($ago));
    }
}
