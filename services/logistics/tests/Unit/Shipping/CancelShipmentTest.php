<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Logistics\Shipping\Application\CancellationOutcome;
use Logistics\Shipping\Application\CancelledOrder;
use Logistics\Shipping\Application\UseCase\CancelShipment;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;
use Logistics\Shipping\Domain\Event\ShipmentCancelled;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;
use Tests\Doubles\Shared\DirectTransactions;
use Tests\Doubles\Shared\InMemoryInbox;
use Tests\Doubles\Shared\RecordedEvents;
use Tests\Doubles\Shipping\InMemoryCancelledOrders;
use Tests\Doubles\Shipping\InMemoryShipments;
use Tucano\SharedKernel\Time\FrozenClock;

final class CancelShipmentTest extends TestCase
{
    private const string ORDER = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    private InMemoryShipments $shipments;

    private RecordedEvents $events;

    private InMemoryCancelledOrders $cancelledOrders;

    private CancelShipment $cancelShipment;

    protected function setUp(): void
    {
        $this->shipments = new InMemoryShipments();
        $this->events = new RecordedEvents();
        $this->cancelledOrders = new InMemoryCancelledOrders();
        $this->cancelShipment = new CancelShipment(
            new DirectTransactions(),
            new InMemoryInbox(),
            $this->shipments,
            $this->cancelledOrders,
            $this->events,
            new FrozenClock('2026-09-27T13:00:00Z'),
        );
    }

    /** @return iterable<string, array{ShipmentStatus}> */
    public static function stillInTheWarehouse(): iterable
    {
        yield 'created' => [ShipmentStatus::Created];
        yield 'ready for pickup, with its label' => [ShipmentStatus::ReadyForPickup];
    }

    #[Test]
    #[DataProvider('stillInTheWarehouse')]
    public function a_shipment_still_in_the_warehouse_is_cancelled(ShipmentStatus $status): void
    {
        $this->given($status);

        self::assertSame(CancellationOutcome::Cancelled, $this->cancelShipment->cancel(self::cancelledOrder('event-1')));

        self::assertSame(ShipmentStatus::Cancelled, $this->shipments->forOrder(OrderId::fromString(self::ORDER))?->status);
        self::assertCount(1, $this->events->events);
        self::assertInstanceOf(ShipmentCancelled::class, $this->events->events[0]);
        self::assertSame(['previousStatus' => $status->value, 'reason' => 'order_cancelled'], array_intersect_key(
            $this->events->events[0]->payload(),
            ['previousStatus' => true, 'reason' => true],
        ));
    }

    #[Test]
    public function an_order_without_a_shipment_is_remembered_so_its_payment_does_not_ship_it(): void
    {
        self::assertSame(CancellationOutcome::NoShipment, $this->cancelShipment->cancel(self::cancelledOrder('event-1')));

        self::assertSame([], $this->events->events);
        self::assertTrue($this->cancelledOrders->has(OrderId::fromString(self::ORDER)));
    }

    #[Test]
    public function the_same_event_twice_cancels_once(): void
    {
        $this->given(ShipmentStatus::Created);

        $this->cancelShipment->cancel(self::cancelledOrder('event-1'));

        self::assertSame(CancellationOutcome::Repeated, $this->cancelShipment->cancel(self::cancelledOrder('event-1')));
        self::assertCount(1, $this->events->events);
    }

    #[Test]
    public function a_shipment_that_left_the_warehouse_stays_on_its_way(): void
    {
        $this->given(ShipmentStatus::PickedUp);

        try {
            $this->cancelShipment->cancel(self::cancelledOrder('event-1'));
            self::fail('A shipment on its way cannot be cancelled.');
        } catch (TransitionNotAllowed $refusal) {
            self::assertStringContainsString('picked_up and cannot move to cancelled', $refusal->getMessage());
        }

        self::assertSame(ShipmentStatus::PickedUp, $this->shipments->forOrder(OrderId::fromString(self::ORDER))?->status);
        self::assertSame([], $this->events->events);
    }

    private function given(ShipmentStatus $status): void
    {
        $shipment = ShipmentBuilder::aShipment()->forOrder(OrderId::fromString(self::ORDER))->in($status);
        $shipment->releaseEvents();
        $this->shipments->add($shipment);
    }

    private static function cancelledOrder(string $eventId): CancelledOrder
    {
        return new CancelledOrder($eventId, OrderId::fromString(self::ORDER));
    }
}
