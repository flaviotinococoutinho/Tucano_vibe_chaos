<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shipping\Application\BookingOutcome;
use Logistics\Shipping\Application\PickupOrder;
use Logistics\Shipping\Application\Port\Driven\ForLocatingFulfillmentCenters;
use Logistics\Shipping\Application\Port\Driven\ForSchedulingPickups;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\Port\Driving\ForBookingPickups;
use Logistics\Shipping\Domain\Error\InvalidShipment;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * The first half of UC-SHP-04: with the label ready, the carrier is asked to
 * come. It reacts to ShipmentReadyForPickup, like the label reacts to
 * ShipmentCreated, and the call to the carrier happens outside any transaction.
 */
#[UseCase('UC-SHP-04')]
final readonly class BookPickup implements ForBookingPickups
{
    public function __construct(
        private ForStoringShipments $shipments,
        private ForLocatingFulfillmentCenters $centers,
        private ForSchedulingPickups $carriers,
    ) {}

    public function book(ShipmentId $shipment): BookingOutcome
    {
        $snapshot = $this->shipments->withId($shipment)?->toSnapshot() ?? throw InvalidShipment::because(sprintf('Shipment %s does not exist.', $shipment));
        if ($snapshot->status !== ShipmentStatus::ReadyForPickup) {
            return BookingOutcome::NotNeeded;
        }

        $this->carriers->schedule(new PickupOrder($snapshot, $this->centers->stateOf($snapshot->origin)));

        return BookingOutcome::Booked;
    }
}
