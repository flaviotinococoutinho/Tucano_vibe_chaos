<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use DateTimeImmutable;
use Logistics\CarrierSelection\Domain\Consignment;
use Logistics\CarrierSelection\Domain\NoCarrierFits;
use Logistics\Shipping\Application\CreatedShipment;
use Logistics\Shipping\Application\OrderLine;
use Logistics\Shipping\Application\PaidOrder;
use Logistics\Shipping\Application\ShipmentSkipped;
use Logistics\Shipping\Application\UseCase\CreateShipment;
use Logistics\Shipping\Domain\Error\NoCarrierChosen;
use Logistics\Shipping\Domain\Error\ProductNotSyncedYet;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Parcel\Quantity;
use Logistics\Shipping\Domain\Product\Sku;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\Recipient;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;
use Tests\Doubles\Shared\DirectTransactions;
use Tests\Doubles\Shared\InMemoryInbox;
use Tests\Doubles\Shared\RecordedEvents;
use Tests\Doubles\Shipping\FixedCarrier;
use Tests\Doubles\Shipping\InMemoryCancelledOrders;
use Tests\Doubles\Shipping\InMemoryCatalog;
use Tests\Doubles\Shipping\InMemoryShipments;
use Tests\Doubles\Shipping\SequentialTrackingCodes;
use Tucano\SharedKernel\Domain\ErrorCategory;
use Tucano\SharedKernel\Time\FrozenClock;

final class CreateShipmentTest extends TestCase
{
    private const string ORDER = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    private InMemoryShipments $shipments;

    private FixedCarrier $carrier;

    private RecordedEvents $events;

    private InMemoryCancelledOrders $cancelledOrders;

    private CreateShipment $createShipment;

    protected function setUp(): void
    {
        $this->shipments = new InMemoryShipments();
        $this->carrier = new FixedCarrier('correio-nacional');
        $this->events = new RecordedEvents();
        $this->cancelledOrders = new InMemoryCancelledOrders();
        $this->createShipment = new CreateShipment(
            new DirectTransactions(),
            new InMemoryInbox(),
            $this->cancelledOrders,
            new InMemoryCatalog()->add('BOOK-DDD-001', 1100, 240, 170, 40)->add('HOME-MUG-001', 350, 120, 90, 100),
            $this->carrier,
            new SequentialTrackingCodes(),
            $this->shipments,
            $this->events,
            new FrozenClock('2026-09-27T12:10:00Z'),
        );
    }

    #[Test]
    public function a_paid_order_becomes_a_shipment_with_one_parcel_per_line(): void
    {
        $created = $this->createShipment->create(self::paidOrder('event-1', ['BOOK-DDD-001' => 2, 'HOME-MUG-001' => 1]));

        self::assertInstanceOf(CreatedShipment::class, $created);
        self::assertSame('correio-nacional', (string) $created->carrier);
        $snapshot = $this->shipments->forOrder(OrderId::fromString(self::ORDER))?->toSnapshot();
        self::assertNotNull($snapshot);
        self::assertSame(ShipmentStatus::Created, $snapshot->status);
        self::assertEquals($created->shipment, $snapshot->reference);
        self::assertEquals(new DateTimeImmutable('2026-09-27T12:10:00Z'), $snapshot->createdAt);
        self::assertEquals(
            Parcels::of(ShipmentBuilder::parcel(2200, 240, 170, 80), ShipmentBuilder::parcel(350, 120, 90, 100)),
            $snapshot->parcels,
        );
        self::assertSame([2550], $this->carrier->weighed);
        self::assertSame(['tucano.logistics.shipment.created'], $this->events->types());
    }

    #[Test]
    public function the_same_event_twice_creates_one_shipment(): void
    {
        $order = self::paidOrder('event-1', ['BOOK-DDD-001' => 1]);

        self::assertInstanceOf(CreatedShipment::class, $this->createShipment->create($order));
        self::assertSame(ShipmentSkipped::Repeated, $this->createShipment->create($order));
        self::assertSame(1, $this->shipments->count());
        self::assertCount(1, $this->events->events);
    }

    #[Test]
    public function a_product_the_catalog_copy_has_not_seen_yet_stops_the_shipment_for_now(): void
    {
        try {
            $this->createShipment->create(self::paidOrder('event-1', ['BOOK-DDD-001' => 1, 'ELEC-MON-027' => 1]));
            self::fail('The shipment should wait for the catalog copy.');
        } catch (ProductNotSyncedYet $missing) {
            self::assertSame(ErrorCategory::Unavailable, $missing->category());
            self::assertStringContainsString('ELEC-MON-027', $missing->getMessage());
        }

        self::assertSame(0, $this->shipments->count());
        self::assertSame([], $this->carrier->weighed);
        self::assertSame([], $this->events->events);
    }

    #[Test]
    public function without_a_carrier_there_is_no_shipment(): void
    {
        $this->carrier->refuseWith(NoCarrierChosen::because(NoCarrierFits::for(new Consignment('SP', 'SP', 2_000_000))));

        try {
            $this->createShipment->create(self::paidOrder('event-1', ['BOOK-DDD-001' => 1]));
            self::fail('No carrier should have taken the parcels.');
        } catch (NoCarrierChosen) {
            self::assertSame(0, $this->shipments->count());
            self::assertSame([], $this->events->events);
        }
    }

    #[Test]
    public function an_order_cancelled_before_its_payment_got_here_does_not_ship(): void
    {
        $this->cancelledOrders->add(OrderId::fromString(self::ORDER), new DateTimeImmutable('2026-09-27T12:05:00Z'));

        $skipped = $this->createShipment->create(self::paidOrder('event-1', ['BOOK-DDD-001' => 1]));

        self::assertSame(ShipmentSkipped::OrderCancelled, $skipped);
        self::assertSame(0, $this->shipments->count());
        self::assertSame([], $this->carrier->weighed);
        self::assertSame([], $this->events->events);
    }

    /** @param array<string, int> $lines SKU => quantity */
    private static function paidOrder(string $eventId, array $lines): PaidOrder
    {
        $orderLines = [];
        foreach ($lines as $sku => $quantity) {
            $orderLines[] = new OrderLine(Sku::of($sku), Quantity::of($quantity));
        }

        return new PaidOrder(
            $eventId,
            OrderId::fromString(self::ORDER),
            Recipient::of('Ana Souza', 'ana@example.com'),
            ShipmentBuilder::destination(),
            FulfillmentCenterCode::of('GRU1'),
            $orderLines,
        );
    }
}
