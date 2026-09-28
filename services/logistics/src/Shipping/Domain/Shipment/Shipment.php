<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use DateTimeImmutable;
use LogicException;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;
use Logistics\Shipping\Domain\Event\DeliveryAttemptFailed;
use Logistics\Shipping\Domain\Event\ShipmentCancelled;
use Logistics\Shipping\Domain\Event\ShipmentCreated;
use Logistics\Shipping\Domain\Event\ShipmentDelivered;
use Logistics\Shipping\Domain\Event\ShipmentInTransit;
use Logistics\Shipping\Domain\Event\ShipmentOutForDelivery;
use Logistics\Shipping\Domain\Event\ShipmentPickedUp;
use Logistics\Shipping\Domain\Event\ShipmentReadyForPickup;
use Logistics\Shipping\Domain\Event\ShipmentReturned;
use Logistics\Shipping\Domain\Event\ShipmentReturning;
use Logistics\Shipping\Domain\Guard\AttemptsBelowLimit;
use Logistics\Shipping\Domain\Guard\FailureReasonRequired;
use Logistics\Shipping\Domain\Guard\HubRequired;
use Logistics\Shipping\Domain\Guard\LabelMustBeAttached;
use Logistics\Shipping\Domain\Guard\ProofOfDeliveryRequired;
use Logistics\Shipping\Domain\Guard\ReturnAllowed;
use Logistics\Shipping\Domain\Guard\TransitionGuard;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\Evidence;
use Logistics\Shipping\Domain\Transition\Hub;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use Logistics\Shipping\Domain\Transition\ShippingLabel;
use Logistics\Shipping\Domain\Transition\TransitionRequest;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Domain\AggregateRoot;

/**
 * Aggregate root of Shipping and the main state machine of the project. Every
 * change asks the table in ShipmentStatus whether the move exists (or throws
 * TransitionNotAllowed), then the guard chain whether the data at hand allows
 * it (or throws TransitionRefused). Each move applied records a status
 * transition for the history and one domain event for the outbox.
 */
final class Shipment extends AggregateRoot
{
    /** @var list<StatusTransition> */
    private array $transitions = [];

    /** @var list<DeliveryAttempt> the visits not stored yet */
    private array $visits = [];

    private function __construct(
        private readonly ShipmentReference $reference,
        private readonly CarrierCode $carrier,
        private readonly FulfillmentCenterCode $origin,
        private readonly Recipient $recipient,
        private readonly Address $destination,
        private readonly Parcels $parcels,
        private readonly DateTimeImmutable $createdAt,
        public private(set) ShipmentStatus $status,
        private DeliveryAttempts $attempts,
        private ?ShippingLabel $label,
        public private(set) int $version,
    ) {}

    public static function create(
        ShipmentReference $reference,
        CarrierCode $carrier,
        FulfillmentCenterCode $origin,
        Recipient $recipient,
        Address $destination,
        Parcels $parcels,
        DateTimeImmutable $createdAt,
    ): self {
        $shipment = new self($reference, $carrier, $origin, $recipient, $destination, $parcels, $createdAt, ShipmentStatus::Created, DeliveryAttempts::none(), null, 1);
        $transition = StatusTransition::initial(ShipmentStatus::Created, $createdAt);
        $shipment->transitions[] = $transition;
        $shipment->recordThat(new ShipmentCreated($reference, $transition, $carrier, $origin, $destination, $parcels));

        return $shipment;
    }

    public static function fromSnapshot(ShipmentSnapshot $snapshot): self
    {
        return new self(
            $snapshot->reference,
            $snapshot->carrier,
            $snapshot->origin,
            $snapshot->recipient,
            $snapshot->destination,
            $snapshot->parcels,
            $snapshot->createdAt,
            $snapshot->status,
            $snapshot->attempts,
            $snapshot->label,
            $snapshot->version,
        );
    }

    public function toSnapshot(): ShipmentSnapshot
    {
        return new ShipmentSnapshot(
            $this->reference,
            $this->carrier,
            $this->origin,
            $this->recipient,
            $this->destination,
            $this->parcels,
            $this->status,
            $this->attempts,
            $this->label,
            $this->createdAt,
            $this->version,
        );
    }

    /** The label job stored the label; the shipment waits for the carrier with it. */
    public function markReadyForPickup(?ShippingLabel $label, DateTimeImmutable $at): void
    {
        $transition = $this->moveTo(ShipmentStatus::ReadyForPickup, $at, new Evidence(label: $label));
        $this->label = $label;
        $this->recordThat(new ShipmentReadyForPickup($this->reference, $transition));
    }

    public function recordPickup(DateTimeImmutable $at): void
    {
        $this->recordThat(new ShipmentPickedUp($this->reference, $this->moveTo(ShipmentStatus::PickedUp, $at)));
    }

    public function recordHubScan(?Hub $hub, DateTimeImmutable $at): void
    {
        $this->recordThat(new ShipmentInTransit($this->reference, $this->moveTo(ShipmentStatus::InTransit, $at, new Evidence(hub: $hub))));
    }

    /** Last mile: from the carrier's hub, straight from pickup by the own fleet, or for another attempt. */
    public function sendOutForDelivery(DateTimeImmutable $at): void
    {
        $transition = $this->moveTo(ShipmentStatus::OutForDelivery, $at);
        $this->recordThat(new ShipmentOutForDelivery($this->reference, $transition, $this->attempts->next()));
    }

    public function recordDelivery(?ProofOfDelivery $proof, DateTimeImmutable $at): void
    {
        $transition = $this->moveTo(ShipmentStatus::Delivered, $at, new Evidence(proof: $proof));
        $this->attempts = $this->attempts->succeeded();
        $this->visits[] = DeliveryAttempt::delivered($this->attempts->made, $proof ?? throw new LogicException('The proof guard let a delivery through without its proof.'), $at);
        $this->recordThat(new ShipmentDelivered($this->reference, $transition, $this->attempts->made));
    }

    public function recordFailedAttempt(?DeliveryFailure $failure, DateTimeImmutable $at): void
    {
        $transition = $this->moveTo(ShipmentStatus::DeliveryFailed, $at, new Evidence(failure: $failure));
        $this->attempts = $this->attempts->failed($failure);
        $this->visits[] = DeliveryAttempt::failed($this->attempts->made, $failure ?? throw new LogicException('The reason guard let a failed visit through without its reason.'), $at);
        $this->recordThat(new DeliveryAttemptFailed($this->reference, $transition, $this->attempts->made));
    }

    public function returnToSender(DateTimeImmutable $at): void
    {
        $this->recordThat(new ShipmentReturning($this->reference, $this->moveTo(ShipmentStatus::Returning, $at)));
    }

    public function recordReturn(DateTimeImmutable $at): void
    {
        $this->recordThat(new ShipmentReturned($this->reference, $this->moveTo(ShipmentStatus::Returned, $at)));
    }

    /** Possible only while the parcels are still in the warehouse. */
    public function cancel(CancellationReason $reason, DateTimeImmutable $at): void
    {
        $this->recordThat(new ShipmentCancelled($this->reference, $this->moveTo(ShipmentStatus::Cancelled, $at, new Evidence(cancellation: $reason))));
    }

    /**
     * The transitions not stored yet, oldest first. Like events, they are handed
     * over once: the repository writes them to the history when it saves the shipment.
     *
     * @return list<StatusTransition>
     */
    public function releaseTransitions(): array
    {
        $transitions = $this->transitions;
        $this->transitions = [];

        return $transitions;
    }

    /**
     * The visits to the address not stored yet, oldest first, handed over once like the transitions.
     *
     * @return list<DeliveryAttempt>
     */
    public function releaseVisits(): array
    {
        $visits = $this->visits;
        $this->visits = [];

        return $visits;
    }

    private function moveTo(ShipmentStatus $target, DateTimeImmutable $at, Evidence $evidence = new Evidence()): StatusTransition
    {
        if (!$this->status->canMoveTo($target)) {
            throw TransitionNotAllowed::for($this->reference->trackingCode, $this->status, $target);
        }
        self::guards()->check(new TransitionRequest($target, $this->attempts, $evidence));

        $transition = StatusTransition::between($this->status, $target, $at, $evidence->reason(), $evidence->location());
        $this->transitions[] = $transition;
        $this->status = $target;
        $this->version++;

        return $transition;
    }

    /** The chain of docs/architecture/state-machines.md, plus the guards the transition table asks for. */
    private static function guards(): TransitionGuard
    {
        return new LabelMustBeAttached(
            new HubRequired(
                new ProofOfDeliveryRequired(
                    new FailureReasonRequired(
                        new AttemptsBelowLimit(
                            new ReturnAllowed(),
                        ),
                    ),
                ),
            ),
        );
    }
}
