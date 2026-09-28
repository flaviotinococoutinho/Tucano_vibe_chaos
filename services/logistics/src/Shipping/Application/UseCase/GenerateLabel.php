<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shared\Application\Port\Driven\ForPublishingEvents;
use Logistics\Shared\Application\Port\Driven\ForRunningTransactions;
use Logistics\Shipping\Application\LabelOutcome;
use Logistics\Shipping\Application\Port\Driven\ForPrintingLabels;
use Logistics\Shipping\Application\Port\Driven\ForStoringLabels;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\Port\Driving\ForGeneratingLabels;
use Logistics\Shipping\Domain\Error\InvalidShipment;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Transition\ShippingLabel;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Time\Clock;

/**
 * Prints the label, stores it, and only then moves the shipment to
 * ready_for_pickup, with ShipmentReadyForPickup in the outbox. The storage
 * happens outside any transaction; storing again overwrites the same object,
 * so a job that fails halfway can simply run again.
 */
#[UseCase('UC-SHP-03')]
final readonly class GenerateLabel implements ForGeneratingLabels
{
    public function __construct(
        private ForRunningTransactions $transactions,
        private ForStoringShipments $shipments,
        private ForPrintingLabels $printer,
        private ForStoringLabels $labels,
        private ForPublishingEvents $events,
        private Clock $clock,
    ) {}

    public function generate(ShipmentId $shipment): LabelOutcome
    {
        // A plain read: nothing stays locked while the label is printed and stored.
        $snapshot = $this->shipments->withId($shipment)?->toSnapshot() ?? throw InvalidShipment::because(sprintf('Shipment %s does not exist.', $shipment));
        if ($snapshot->status !== ShipmentStatus::Created) {
            return LabelOutcome::NotNeeded;
        }

        $label = $this->labels->store($snapshot->reference->trackingCode, $this->printer->print($snapshot));

        return $this->transactions->run(fn(): LabelOutcome => $this->attach($shipment, $label));
    }

    private function attach(ShipmentId $id, ShippingLabel $label): LabelOutcome
    {
        $shipment = $this->shipments->withId($id);
        // Cancelled while the label was printed: the object stays in the bucket, unused.
        if ($shipment === null || $shipment->toSnapshot()->status !== ShipmentStatus::Created) {
            return LabelOutcome::NotNeeded;
        }
        $shipment->markReadyForPickup($label, $this->clock->now());
        $this->shipments->save($shipment);
        $this->events->publish(...$shipment->releaseEvents());

        return LabelOutcome::Attached;
    }
}
