<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Logistics\Shipping\Adapter\Driven\ZplLabels;
use Logistics\Shipping\Application\LabelOutcome;
use Logistics\Shipping\Application\UseCase\GenerateLabel;
use Logistics\Shipping\Domain\Error\InvalidShipment;
use Logistics\Shipping\Domain\Error\LabelNotStored;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;
use Tests\Doubles\Shared\DirectTransactions;
use Tests\Doubles\Shared\RecordedEvents;
use Tests\Doubles\Shipping\InMemoryLabels;
use Tests\Doubles\Shipping\InMemoryShipments;
use Tucano\SharedKernel\Time\FrozenClock;

final class GenerateLabelTest extends TestCase
{
    private InMemoryShipments $shipments;

    private InMemoryLabels $labels;

    private RecordedEvents $events;

    private GenerateLabel $generateLabel;

    protected function setUp(): void
    {
        $this->shipments = new InMemoryShipments();
        $this->labels = new InMemoryLabels();
        $this->events = new RecordedEvents();
        $this->generateLabel = new GenerateLabel(
            new DirectTransactions(),
            $this->shipments,
            new ZplLabels(),
            $this->labels,
            $this->events,
            new FrozenClock('2026-09-27T12:20:00Z'),
        );
    }

    #[Test]
    public function a_created_shipment_gets_its_label_and_waits_for_pickup(): void
    {
        $shipment = $this->given(ShipmentStatus::Created);
        $trackingCode = (string) $shipment->toSnapshot()->reference->trackingCode;

        $outcome = $this->generateLabel->generate($this->idOf($shipment));

        self::assertSame(LabelOutcome::Attached, $outcome);
        self::assertSame(["labels/{$trackingCode}.zpl"], array_keys($this->labels->stored));
        $snapshot = $shipment->toSnapshot();
        self::assertSame([ShipmentStatus::ReadyForPickup, "labels/{$trackingCode}.zpl"], [$snapshot->status, $snapshot->label?->objectKey]);
        self::assertSame(['tucano.logistics.shipment.ready_for_pickup'], $this->events->types());
    }

    #[Test]
    public function the_same_job_twice_stores_one_label_and_publishes_once(): void
    {
        $shipment = $this->given(ShipmentStatus::Created);

        $this->generateLabel->generate($this->idOf($shipment));

        self::assertSame(LabelOutcome::NotNeeded, $this->generateLabel->generate($this->idOf($shipment)));
        self::assertCount(1, $this->labels->stored);
        self::assertCount(1, $this->events->events);
    }

    #[Test]
    public function a_cancelled_shipment_needs_no_label(): void
    {
        $shipment = $this->given(ShipmentStatus::Cancelled);

        self::assertSame(LabelOutcome::NotNeeded, $this->generateLabel->generate($this->idOf($shipment)));
        self::assertSame([], $this->labels->stored);
        self::assertSame([], $this->events->events);
    }

    #[Test]
    public function a_label_not_stored_leaves_the_shipment_as_it_was_for_the_next_try(): void
    {
        $shipment = $this->given(ShipmentStatus::Created);
        $this->labels->failNextTimes(1);

        try {
            $this->generateLabel->generate($this->idOf($shipment));
            self::fail('The failure of the bucket was expected.');
        } catch (LabelNotStored) {
            self::assertSame(ShipmentStatus::Created, $shipment->toSnapshot()->status);
            self::assertSame([], $this->events->events);
        }

        self::assertSame(LabelOutcome::Attached, $this->generateLabel->generate($this->idOf($shipment)));
    }

    #[Test]
    public function a_shipment_that_does_not_exist_is_a_mistake_of_the_request(): void
    {
        $this->expectException(InvalidShipment::class);

        $this->generateLabel->generate(ShipmentId::generate());
    }

    private function given(ShipmentStatus $status): Shipment
    {
        $shipment = ShipmentBuilder::aShipment()->in($status);
        $shipment->releaseEvents();
        $this->shipments->add($shipment);

        return $shipment;
    }

    private function idOf(Shipment $shipment): ShipmentId
    {
        return $shipment->toSnapshot()->reference->id;
    }
}
