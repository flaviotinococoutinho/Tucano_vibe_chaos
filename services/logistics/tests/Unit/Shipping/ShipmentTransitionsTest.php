<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Closure;
use DateTimeImmutable;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;
use Logistics\Shipping\Domain\Event\DeliveryAttemptFailed;
use Logistics\Shipping\Domain\Event\ShipmentCancelled;
use Logistics\Shipping\Domain\Event\ShipmentDelivered;
use Logistics\Shipping\Domain\Event\ShipmentEvent;
use Logistics\Shipping\Domain\Event\ShipmentInTransit;
use Logistics\Shipping\Domain\Event\ShipmentOutForDelivery;
use Logistics\Shipping\Domain\Event\ShipmentPickedUp;
use Logistics\Shipping\Domain\Event\ShipmentReadyForPickup;
use Logistics\Shipping\Domain\Event\ShipmentReturned;
use Logistics\Shipping\Domain\Event\ShipmentReturning;
use Logistics\Shipping\Domain\Shipment\CancellationReason;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Shipment\StatusTransition;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\Hub;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use Logistics\Shipping\Domain\Transition\ShippingLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;

/** Every edge of the transition table, through the aggregate: new status, one history line and one event. */
final class ShipmentTransitionsTest extends TestCase
{
    /**
     * @return iterable<string, array{
     *     Closure(): Shipment,
     *     Closure(Shipment, DateTimeImmutable): void,
     *     ShipmentStatus,
     *     class-string<ShipmentEvent>,
     *     array<string, mixed>,
     * }>
     */
    public static function transitions(): iterable
    {
        $in = static fn(ShipmentStatus $status): Closure => static fn(): Shipment => ShipmentBuilder::aShipment()->in($status);
        $label = ShippingLabel::storedAt('labels/2026/09/27/label.pdf');
        $proof = ProofOfDelivery::of('Ana Souza', '12345678900');

        yield 'created to ready_for_pickup, once the label is attached' => [
            $in(ShipmentStatus::Created),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->markReadyForPickup($label, $at),
            ShipmentStatus::ReadyForPickup, ShipmentReadyForPickup::class, [],
        ];
        yield 'created to cancelled, when the order is cancelled' => [
            $in(ShipmentStatus::Created),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->cancel(CancellationReason::OrderCancelled, $at),
            ShipmentStatus::Cancelled, ShipmentCancelled::class, ['previousStatus' => 'created', 'reason' => 'order_cancelled'],
        ];
        yield 'ready_for_pickup to picked_up, at the pickup' => [
            $in(ShipmentStatus::ReadyForPickup),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordPickup($at),
            ShipmentStatus::PickedUp, ShipmentPickedUp::class, [],
        ];
        yield 'ready_for_pickup to cancelled, when the order is cancelled' => [
            $in(ShipmentStatus::ReadyForPickup),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->cancel(CancellationReason::OrderCancelled, $at),
            ShipmentStatus::Cancelled, ShipmentCancelled::class, ['previousStatus' => 'ready_for_pickup', 'reason' => 'order_cancelled'],
        ];
        yield 'picked_up to in_transit, at the first hub' => [
            $in(ShipmentStatus::PickedUp),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordHubScan(Hub::named('Hub Cajamar'), $at),
            ShipmentStatus::InTransit, ShipmentInTransit::class, ['hub' => 'Hub Cajamar'],
        ];
        yield 'picked_up to out_for_delivery, with the own fleet' => [
            $in(ShipmentStatus::PickedUp),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->sendOutForDelivery($at),
            ShipmentStatus::OutForDelivery, ShipmentOutForDelivery::class, ['attempt' => 1],
        ];
        yield 'in_transit to in_transit, at the next hub' => [
            $in(ShipmentStatus::InTransit),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordHubScan(Hub::named('Hub Contagem'), $at),
            ShipmentStatus::InTransit, ShipmentInTransit::class, ['hub' => 'Hub Contagem'],
        ];
        yield 'in_transit to out_for_delivery, on the last mile' => [
            $in(ShipmentStatus::InTransit),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->sendOutForDelivery($at),
            ShipmentStatus::OutForDelivery, ShipmentOutForDelivery::class, ['attempt' => 1],
        ];
        yield 'out_for_delivery to delivered, with the proof' => [
            $in(ShipmentStatus::OutForDelivery),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordDelivery($proof, $at),
            ShipmentStatus::Delivered, ShipmentDelivered::class, ['attempt' => 1],
        ];
        yield 'out_for_delivery to delivery_failed, with the reason' => [
            $in(ShipmentStatus::OutForDelivery),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordFailedAttempt(DeliveryFailure::AddressNotFound, $at),
            ShipmentStatus::DeliveryFailed, DeliveryAttemptFailed::class, ['attempt' => 1, 'reason' => 'address_not_found'],
        ];
        yield 'delivery_failed to out_for_delivery, for another attempt' => [
            $in(ShipmentStatus::DeliveryFailed),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->sendOutForDelivery($at),
            ShipmentStatus::OutForDelivery, ShipmentOutForDelivery::class, ['attempt' => 2],
        ];
        yield 'delivery_failed to returning, after a refusal' => [
            static fn(): Shipment => ShipmentBuilder::aShipment()->refused(),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->returnToSender($at),
            ShipmentStatus::Returning, ShipmentReturning::class, [],
        ];
        yield 'returning to returned, back at the fulfillment center' => [
            $in(ShipmentStatus::Returning),
            static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordReturn($at),
            ShipmentStatus::Returned, ShipmentReturned::class, [],
        ];
    }

    /**
     * @param Closure(): Shipment $given
     * @param Closure(Shipment, DateTimeImmutable): void $when
     * @param class-string<ShipmentEvent> $event
     * @param array<string, mixed> $details
     */
    #[Test]
    #[DataProvider('transitions')]
    public function a_transition_moves_the_shipment_records_it_and_announces_it(Closure $given, Closure $when, ShipmentStatus $then, string $event, array $details): void
    {
        $shipment = $given();
        $shipment->releaseEvents();
        $shipment->releaseTransitions();
        $from = $shipment->status;
        $version = $shipment->version;
        $at = new DateTimeImmutable('2026-09-28T09:30:00Z');

        $when($shipment, $at);

        self::assertSame($then, $shipment->status);
        self::assertSame($version + 1, $shipment->version);
        $transitions = $shipment->releaseTransitions();
        self::assertCount(1, $transitions);
        self::assertSame([$from, $then, $at], [$transitions[0]->from, $transitions[0]->to, $transitions[0]->at]);
        $events = $shipment->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf($event, $events[0]);
        self::assertSame('tucano.logistics.shipment.' . $then->value, $events[0]->eventType());
        self::assertEquals($at, $events[0]->occurredAt());
        self::assertSame($details, array_diff_key($events[0]->payload(), array_flip(['shipmentId', 'trackingCode', 'orderId'])));
    }

    #[Test]
    public function the_examples_above_cover_every_edge_of_the_table(): void
    {
        $covered = [];
        foreach (self::transitions() as [$given, , $then]) {
            $covered[] = $given()->status->value . ' -> ' . $then->value;
        }
        $edges = [];
        foreach (ShipmentStatus::cases() as $from) {
            foreach ($from->next() as $to) {
                $edges[] = $from->value . ' -> ' . $to->value;
            }
        }

        self::assertEqualsCanonicalizing($edges, $covered);
    }

    /** @return iterable<string, array{ShipmentStatus, Closure(Shipment, DateTimeImmutable): void, string}> */
    public static function movesOutsideTheTable(): iterable
    {
        yield 'picked up with no label ever attached' => [ShipmentStatus::Created, static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordPickup($at), 'created and cannot move to picked_up'];
        yield 'cancelled after pickup' => [ShipmentStatus::PickedUp, static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->cancel(CancellationReason::OrderCancelled, $at), 'picked_up and cannot move to cancelled'];
        yield 'cancelled twice' => [ShipmentStatus::Cancelled, static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->cancel(CancellationReason::OrderCancelled, $at), 'cancelled and cannot move to cancelled'];
        yield 'delivered again' => [ShipmentStatus::Delivered, static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->sendOutForDelivery($at), 'delivered and cannot move to out_for_delivery'];
        yield 'returned without leaving' => [ShipmentStatus::InTransit, static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordReturn($at), 'in_transit and cannot move to returned'];
    }

    /** @param Closure(Shipment, DateTimeImmutable): void $move */
    #[Test]
    #[DataProvider('movesOutsideTheTable')]
    public function a_move_outside_the_table_is_not_allowed(ShipmentStatus $from, Closure $move, string $message): void
    {
        $shipment = ShipmentBuilder::aShipment()->in($from);

        $this->expectException(TransitionNotAllowed::class);
        $this->expectExceptionMessage($message);

        $move($shipment, new DateTimeImmutable('2026-09-28T09:30:00Z'));
    }

    #[Test]
    public function the_history_keeps_the_hub_and_the_reasons(): void
    {
        $shipment = ShipmentBuilder::aShipment()->in(ShipmentStatus::Returning);

        $details = array_map(
            static fn(StatusTransition $transition): array => [$transition->to->value, $transition->reason, $transition->location],
            $shipment->releaseTransitions(),
        );

        self::assertSame([
            ['created', null, null],
            ['ready_for_pickup', null, null],
            ['picked_up', null, null],
            ['in_transit', null, 'Hub Cajamar'],
            ['out_for_delivery', null, null],
            ['delivery_failed', 'recipient_refused', null],
            ['returning', null, null],
        ], $details);
    }

    #[Test]
    public function transitions_are_handed_over_once(): void
    {
        $shipment = ShipmentBuilder::aShipment()->create();
        $shipment->releaseTransitions();

        self::assertSame([], $shipment->releaseTransitions());
    }
}
