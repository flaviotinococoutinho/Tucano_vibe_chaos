<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\Hub;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;

/**
 * An event of the journey as the carrier tells it, already in the language of
 * Shipping: the report (which event, which parcels, when) and the step, with
 * what the step needs. A webhook and the tracking history both become these,
 * and both go through applyTo, so a step lands the same way from either side.
 */
final readonly class CarrierEvent
{
    private function __construct(
        public CarrierReport $report,
        public CarrierStep $step,
        private ?Hub $hub = null,
        private ?ProofOfDelivery $proof = null,
        private ?DeliveryFailure $failure = null,
    ) {}

    public static function pickedUp(CarrierReport $report): self
    {
        return new self($report, CarrierStep::PickedUp);
    }

    public static function hubScanned(CarrierReport $report, Hub $hub): self
    {
        return new self($report, CarrierStep::HubScanned, hub: $hub);
    }

    public static function outForDelivery(CarrierReport $report): self
    {
        return new self($report, CarrierStep::OutForDelivery);
    }

    public static function delivered(CarrierReport $report, ProofOfDelivery $proof): self
    {
        return new self($report, CarrierStep::Delivered, proof: $proof);
    }

    public static function deliveryFailed(CarrierReport $report, DeliveryFailure $failure): self
    {
        return new self($report, CarrierStep::DeliveryFailed, failure: $failure);
    }

    public static function returning(CarrierReport $report): self
    {
        return new self($report, CarrierStep::Returning);
    }

    public static function returned(CarrierReport $report): self
    {
        return new self($report, CarrierStep::Returned);
    }

    /** Hands the event to the use case of its step. */
    public function applyTo(CarrierJourney $journey): ProgressOutcome
    {
        return match ($this->step) {
            CarrierStep::PickedUp => $journey->pickups->recordPickup($this->report),
            CarrierStep::HubScanned => $journey->hubScans->recordHubScan($this->report, $this->hub),
            CarrierStep::OutForDelivery => $journey->dispatches->dispatch($this->report),
            CarrierStep::Delivered => $journey->visits->recordDelivery($this->report, $this->proof),
            CarrierStep::DeliveryFailed => $journey->visits->recordFailedVisit($this->report, $this->failure),
            CarrierStep::Returning => $journey->returns->startReturn($this->report),
            CarrierStep::Returned => $journey->returns->completeReturn($this->report),
        };
    }
}
