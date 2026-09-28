<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shipping\Application\CarrierJourney;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\Port\Driven\ForTrackingPickups;
use Logistics\Shipping\Application\Port\Driving\ForReconcilingJourneys;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Application\ReconciledJourney;
use Logistics\Shipping\Domain\Error\CarrierUnreachable;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Time\Clock;

/**
 * UC-SHP-12: a shipment in the hands of a carrier that goes quiet for too long
 * may have lost a webhook, and every event after the lost one is refused as
 * early. The carrier keeps the whole history, so the reconciliation reads it
 * and plays it in order through the same use cases as the webhook: the inbox
 * skips what is already in, and the missing steps land in their place.
 */
#[UseCase('UC-SHP-12')]
final readonly class ReconcileJourneys implements ForReconcilingJourneys
{
    public function __construct(
        private ForStoringShipments $shipments,
        private ForTrackingPickups $carriers,
        private CarrierJourney $journey,
        private Clock $clock,
        private int $quietSeconds,
    ) {}

    public function reconcileNext(): ?ReconciledJourney
    {
        $now = $this->clock->now();
        $shipment = $this->shipments->claimQuiet($now->modify(sprintf('-%d seconds', $this->quietSeconds)), $now);
        if ($shipment === null) {
            return null;
        }

        try {
            $history = $this->carriers->eventsOf($shipment->id);
        } catch (CarrierUnreachable) {
            // The claim keeps it out of the way until it is quiet again; then it is asked about again.
            return ReconciledJourney::carrierUnreachable($shipment->trackingCode);
        }

        $applied = 0;
        foreach ($history as $event) {
            try {
                $applied += $event->applyTo($this->journey) === ProgressOutcome::Applied ? 1 : 0;
            } catch (DomainError $refused) {
                return ReconciledJourney::stopped($shipment->trackingCode, $applied, $refused->getMessage());
            }
        }

        return $applied === 0 ? ReconciledJourney::upToDate($shipment->trackingCode) : ReconciledJourney::caughtUp($shipment->trackingCode, $applied);
    }
}
