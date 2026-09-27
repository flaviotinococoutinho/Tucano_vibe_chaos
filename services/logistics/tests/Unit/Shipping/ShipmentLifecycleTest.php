<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Closure;
use DateTimeImmutable;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;
use Logistics\Shipping\Domain\Error\TransitionRefused;
use Logistics\Shipping\Domain\Event\ShipmentCreated;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Shipment\StatusTransition;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;
use Tucano\SharedKernel\Domain\DomainEvent;

final class ShipmentLifecycleTest extends TestCase
{
    #[Test]
    public function a_new_shipment_starts_its_history_and_announces_itself(): void
    {
        $shipment = ShipmentBuilder::aShipment()
            ->createdAt('2026-09-27T12:00:00Z')
            ->withParcels(ShipmentBuilder::parcel(2200, 240, 170, 80), ShipmentBuilder::parcel(350, 120, 90, 100))
            ->create();

        self::assertSame(ShipmentStatus::Created, $shipment->status);
        self::assertSame(1, $shipment->version);
        self::assertEquals(
            [new StatusTransition(null, ShipmentStatus::Created, new DateTimeImmutable('2026-09-27T12:00:00Z'))],
            $shipment->releaseTransitions(),
        );
        $events = $shipment->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ShipmentCreated::class, $events[0]);
        self::assertSame('tucano.logistics.shipment.created', $events[0]->eventType());
        self::assertSame($shipment->toSnapshot()->reference->id->toString(), $events[0]->aggregateId());
        $payload = $events[0]->payload();
        self::assertMatchesRegularExpression('/^TX[0-9A-HJKMNP-TV-Z]{13}$/', $payload['trackingCode']);
        self::assertSame(['tucano-express', 'GRU1', 2550], [$payload['carrier'], $payload['origin'], $payload['totalWeightGrams']]);
        self::assertSame(['city' => 'São Paulo', 'state' => 'SP', 'postalCode' => '01310100'], $payload['destination']);
        self::assertSame([
            ['weightGrams' => 2200, 'dimensions' => ['lengthMm' => 240, 'widthMm' => 170, 'heightMm' => 80]],
            ['weightGrams' => 350, 'dimensions' => ['lengthMm' => 120, 'widthMm' => 90, 'heightMm' => 100]],
        ], $payload['parcels']);
    }

    #[Test]
    public function the_shipment_event_leaves_the_street_and_the_recipient_out(): void
    {
        $payload = ShipmentBuilder::aShipment()->create()->releaseEvents()[0]->payload();

        self::assertStringNotContainsString('Paulista', (string) json_encode($payload));
        self::assertStringNotContainsString('ana@example.com', (string) json_encode($payload));
    }

    /** @return iterable<string, array{ShipmentStatus, Closure(Shipment, DateTimeImmutable): void, TransitionRefused}> */
    public static function refusedByAGuard(): iterable
    {
        yield 'ready for pickup without the label' => [ShipmentStatus::Created, static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->markReadyForPickup(null, $at), TransitionRefused::withoutLabel()];
        yield 'a hub scan without the hub' => [ShipmentStatus::PickedUp, static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordHubScan(null, $at), TransitionRefused::withoutHub()];
        yield 'delivered without a proof' => [ShipmentStatus::OutForDelivery, static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordDelivery(null, $at), TransitionRefused::withoutProofOfDelivery()];
        yield 'a failed delivery without the reason' => [ShipmentStatus::OutForDelivery, static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordFailedAttempt(null, $at), TransitionRefused::withoutFailureReason()];
        yield 'back to the sender after one absence' => [ShipmentStatus::DeliveryFailed, static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->returnToSender($at), TransitionRefused::returnNotJustified(3)];
    }

    /** @param Closure(Shipment, DateTimeImmutable): void $move */
    #[Test]
    #[DataProvider('refusedByAGuard')]
    public function a_guard_refusal_leaves_the_shipment_as_it_was(ShipmentStatus $from, Closure $move, TransitionRefused $refusal): void
    {
        $shipment = ShipmentBuilder::aShipment()->in($from);
        $shipment->releaseEvents();
        $shipment->releaseTransitions();
        $before = $shipment->toSnapshot();

        try {
            $move($shipment, new DateTimeImmutable('2026-09-28T09:30:00Z'));
            self::fail('The guard should have refused the transition.');
        } catch (TransitionRefused $refused) {
            self::assertEquals($refusal, $refused);
        }

        self::assertEquals($before, $shipment->toSnapshot());
        self::assertSame([], $shipment->releaseEvents());
        self::assertSame([], $shipment->releaseTransitions());
    }

    #[Test]
    public function the_table_is_asked_before_the_guards(): void
    {
        $shipment = ShipmentBuilder::aShipment()->create();

        $this->expectException(TransitionNotAllowed::class);

        $shipment->recordDelivery(null, new DateTimeImmutable());
    }

    #[Test]
    public function it_refuses_a_fourth_delivery_attempt(): void
    {
        $shipment = ShipmentBuilder::aShipment()->in(ShipmentStatus::OutForDelivery);
        $at = new DateTimeImmutable('2026-09-28T09:00:00Z');
        $shipment->recordFailedAttempt(DeliveryFailure::RecipientAbsent, $at);
        $shipment->sendOutForDelivery($at->modify('+1 day'));
        $shipment->recordFailedAttempt(DeliveryFailure::RecipientAbsent, $at->modify('+1 day'));
        $shipment->sendOutForDelivery($at->modify('+2 days'));
        $shipment->recordFailedAttempt(DeliveryFailure::AddressNotFound, $at->modify('+2 days'));

        $this->expectExceptionObject(TransitionRefused::attemptsExhausted(3));

        $shipment->sendOutForDelivery($at->modify('+3 days'));
    }

    #[Test]
    public function after_the_third_failure_the_shipment_goes_back_to_the_sender(): void
    {
        $shipment = ShipmentBuilder::aShipment()->in(ShipmentStatus::OutForDelivery);
        $at = new DateTimeImmutable('2026-09-28T09:00:00Z');
        $shipment->recordFailedAttempt(DeliveryFailure::RecipientAbsent, $at);
        foreach (['+1 day', '+2 days'] as $later) {
            $shipment->sendOutForDelivery($at->modify($later));
            $shipment->recordFailedAttempt(DeliveryFailure::RecipientAbsent, $at->modify($later));
        }
        $shipment->releaseEvents();

        $shipment->returnToSender($at->modify('+3 days'));
        $shipment->recordReturn($at->modify('+5 days'));

        self::assertSame(ShipmentStatus::Returned, $shipment->status);
        self::assertSame(['tucano.logistics.shipment.returning', 'tucano.logistics.shipment.returned'], array_map(
            static fn(DomainEvent $event): string => $event->eventType(),
            $shipment->releaseEvents(),
        ));
        self::assertSame(3, $shipment->toSnapshot()->attempts->made);
    }

    #[Test]
    public function a_second_attempt_can_deliver_after_a_failed_one(): void
    {
        $shipment = ShipmentBuilder::aShipment()->in(ShipmentStatus::DeliveryFailed);
        $shipment->sendOutForDelivery(new DateTimeImmutable('2026-09-29T09:00:00Z'));
        $shipment->releaseEvents();

        $shipment->recordDelivery(ProofOfDelivery::of('Bruno Souza', '98765432100'), new DateTimeImmutable('2026-09-29T11:00:00Z'));

        self::assertSame(ShipmentStatus::Delivered, $shipment->status);
        self::assertSame(2, $shipment->releaseEvents()[0]->payload()['attempt']);
        self::assertTrue($shipment->status->isFinal());
    }

    #[Test]
    public function every_transition_bumps_the_version(): void
    {
        // Created at version 1, then labelled, picked up, scanned, out, refused, returning and returned.
        self::assertSame(8, ShipmentBuilder::aShipment()->in(ShipmentStatus::Returned)->version);
    }

    #[Test]
    public function the_snapshot_rebuilds_the_same_shipment(): void
    {
        $shipment = ShipmentBuilder::aShipment()->refused();

        $rebuilt = Shipment::fromSnapshot($shipment->toSnapshot());

        self::assertEquals($shipment->toSnapshot(), $rebuilt->toSnapshot());
        self::assertSame([], $rebuilt->releaseEvents());
        self::assertSame([], $rebuilt->releaseTransitions());
        $rebuilt->returnToSender(new DateTimeImmutable('2026-09-29T09:00:00Z'));
        self::assertSame(ShipmentStatus::Returning, $rebuilt->status);
    }
}
