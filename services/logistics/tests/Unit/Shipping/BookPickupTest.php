<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Logistics\Shipping\Application\BookingOutcome;
use Logistics\Shipping\Application\Port\Driven\ForLocatingFulfillmentCenters;
use Logistics\Shipping\Application\UseCase\BookPickup;
use Logistics\Shipping\Domain\Destination\BrazilianState;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;
use Tests\Doubles\Shipping\InMemoryShipments;
use Tests\Doubles\Shipping\RecordedPickups;

final class BookPickupTest extends TestCase
{
    private InMemoryShipments $shipments;

    private RecordedPickups $carriers;

    private BookPickup $bookPickup;

    protected function setUp(): void
    {
        $this->shipments = new InMemoryShipments();
        $this->carriers = new RecordedPickups();
        $this->bookPickup = new BookPickup(
            $this->shipments,
            new class implements ForLocatingFulfillmentCenters {
                public function stateOf(FulfillmentCenterCode $center): BrazilianState
                {
                    return BrazilianState::SP;
                }
            },
            $this->carriers,
        );
    }

    #[Test]
    public function a_shipment_with_its_label_asks_its_carrier_to_come(): void
    {
        $shipment = $this->given(ShipmentStatus::ReadyForPickup);

        $outcome = $this->bookPickup->book($shipment->toSnapshot()->reference->id);

        self::assertSame(BookingOutcome::Booked, $outcome);
        self::assertCount(1, $this->carriers->orders);
        self::assertSame([BrazilianState::SP, (string) $shipment->toSnapshot()->reference->trackingCode], [$this->carriers->orders[0]->originState, (string) $this->carriers->orders[0]->shipment->reference->trackingCode]);
    }

    #[Test]
    public function a_shipment_no_longer_waiting_for_pickup_needs_no_carrier(): void
    {
        $shipment = $this->given(ShipmentStatus::Cancelled);

        self::assertSame(BookingOutcome::NotNeeded, $this->bookPickup->book($shipment->toSnapshot()->reference->id));
        self::assertSame([], $this->carriers->orders);
    }

    private function given(ShipmentStatus $status): Shipment
    {
        $shipment = ShipmentBuilder::aShipment()->in($status);
        $this->shipments->add($shipment);

        return $shipment;
    }
}
