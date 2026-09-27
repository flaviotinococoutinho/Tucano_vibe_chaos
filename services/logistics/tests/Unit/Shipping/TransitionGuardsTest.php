<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use ArrayObject;
use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Guard\AttemptsBelowLimit;
use Logistics\Shipping\Domain\Guard\FailureReasonRequired;
use Logistics\Shipping\Domain\Guard\HubRequired;
use Logistics\Shipping\Domain\Guard\LabelMustBeAttached;
use Logistics\Shipping\Domain\Guard\ProofOfDeliveryRequired;
use Logistics\Shipping\Domain\Guard\ReturnAllowed;
use Logistics\Shipping\Domain\Guard\TransitionGuard;
use Logistics\Shipping\Domain\Shipment\DeliveryAttempts;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\Evidence;
use Logistics\Shipping\Domain\Transition\Hub;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use Logistics\Shipping\Domain\Transition\ShippingLabel;
use Logistics\Shipping\Domain\Transition\TransitionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\Shipping\RecordingGuard;
use Tests\Doubles\Shipping\RefusingGuard;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** Each guard alone, and the chain that links them. */
final class TransitionGuardsTest extends TestCase
{
    /** @return iterable<string, array{TransitionGuard, TransitionRequest, TransitionRefused}> */
    public static function refusals(): iterable
    {
        yield 'LabelMustBeAttached, ready for pickup without a label' => [new LabelMustBeAttached(), self::request(ShipmentStatus::ReadyForPickup), TransitionRefused::withoutLabel()];
        yield 'HubRequired, in transit without a hub' => [new HubRequired(), self::request(ShipmentStatus::InTransit), TransitionRefused::withoutHub()];
        yield 'ProofOfDeliveryRequired, delivered without a proof' => [new ProofOfDeliveryRequired(), self::request(ShipmentStatus::Delivered), TransitionRefused::withoutProofOfDelivery()];
        yield 'FailureReasonRequired, failed without a reason' => [new FailureReasonRequired(), self::request(ShipmentStatus::DeliveryFailed), TransitionRefused::withoutFailureReason()];
        yield 'AttemptsBelowLimit, a fourth trip to the address' => [new AttemptsBelowLimit(), self::request(ShipmentStatus::OutForDelivery, self::failures(3)), TransitionRefused::attemptsExhausted(3)];
        yield 'ReturnAllowed, back after one absence' => [new ReturnAllowed(), self::request(ShipmentStatus::Returning, self::failures(1)), TransitionRefused::returnNotJustified(3)];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function a_guard_refuses_its_transition_when_the_data_is_not_there(TransitionGuard $guard, TransitionRequest $request, TransitionRefused $refusal): void
    {
        $this->expectExceptionObject($refusal);

        $guard->check($request);
    }

    /** @return iterable<string, array{TransitionGuard, TransitionRequest}> */
    public static function passes(): iterable
    {
        yield 'LabelMustBeAttached, with the label' => [new LabelMustBeAttached(), self::request(ShipmentStatus::ReadyForPickup, evidence: new Evidence(label: ShippingLabel::storedAt('labels/label.pdf')))];
        yield 'HubRequired, with the hub' => [new HubRequired(), self::request(ShipmentStatus::InTransit, evidence: new Evidence(hub: Hub::named('Hub Cajamar')))];
        yield 'ProofOfDeliveryRequired, with the proof' => [new ProofOfDeliveryRequired(), self::request(ShipmentStatus::Delivered, evidence: new Evidence(proof: ProofOfDelivery::of('Ana Souza', '12345678900')))];
        yield 'FailureReasonRequired, with the reason' => [new FailureReasonRequired(), self::request(ShipmentStatus::DeliveryFailed, evidence: new Evidence(failure: DeliveryFailure::RecipientAbsent))];
        yield 'AttemptsBelowLimit, a third trip' => [new AttemptsBelowLimit(), self::request(ShipmentStatus::OutForDelivery, self::failures(2))];
        yield 'ReturnAllowed, after three failures' => [new ReturnAllowed(), self::request(ShipmentStatus::Returning, self::failures(3))];
        yield 'ReturnAllowed, after a refusal' => [new ReturnAllowed(), self::request(ShipmentStatus::Returning, DeliveryAttempts::none()->failed(DeliveryFailure::RecipientRefused))];
        yield 'a guard ignores the transitions that are not its own' => [new ProofOfDeliveryRequired(), self::request(ShipmentStatus::PickedUp)];
    }

    #[Test]
    #[DataProvider('passes')]
    public function a_guard_lets_the_transition_through_when_its_rule_holds(TransitionGuard $guard, TransitionRequest $request): void
    {
        $this->expectNotToPerformAssertions();

        $guard->check($request);
    }

    #[Test]
    public function each_link_hands_the_request_to_the_next_one(): void
    {
        $visits = new ArrayObject();
        $chain = new RecordingGuard('first', $visits, new RecordingGuard('second', $visits, new RecordingGuard('third', $visits)));

        $chain->check(self::request(ShipmentStatus::PickedUp));

        self::assertSame(['first', 'second', 'third'], $visits->getArrayCopy());
    }

    #[Test]
    public function the_first_refusal_stops_the_chain(): void
    {
        $visits = new ArrayObject();
        $chain = new RecordingGuard('first', $visits, new RefusingGuard(new RecordingGuard('after the refusal', $visits)));

        try {
            $chain->check(self::request(ShipmentStatus::PickedUp));
            self::fail('The chain should have refused the transition.');
        } catch (TransitionRefused) {
            self::assertSame(['first'], $visits->getArrayCopy());
        }
    }

    #[Test]
    public function missing_evidence_is_invalid_input_and_the_attempt_rules_are_conflicts(): void
    {
        self::assertSame(ErrorCategory::InvalidInput, TransitionRefused::withoutProofOfDelivery()->category());
        self::assertSame(ErrorCategory::Conflict, TransitionRefused::attemptsExhausted(3)->category());
        self::assertSame(ErrorCategory::Conflict, TransitionRefused::returnNotJustified(3)->category());
    }

    private static function request(ShipmentStatus $target, ?DeliveryAttempts $attempts = null, Evidence $evidence = new Evidence()): TransitionRequest
    {
        return new TransitionRequest($target, $attempts ?? DeliveryAttempts::none(), $evidence);
    }

    private static function failures(int $count): DeliveryAttempts
    {
        $attempts = DeliveryAttempts::none();
        for ($attempt = 1; $attempt <= $count; $attempt++) {
            $attempts = $attempts->failed(DeliveryFailure::RecipientAbsent);
        }

        return $attempts;
    }
}
