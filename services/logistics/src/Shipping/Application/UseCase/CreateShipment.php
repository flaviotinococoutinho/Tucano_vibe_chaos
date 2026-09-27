<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shared\Application\Port\Driven\ForDeduplicatingMessages;
use Logistics\Shared\Application\Port\Driven\ForPublishingEvents;
use Logistics\Shared\Application\Port\Driven\ForRunningTransactions;
use Logistics\Shipping\Application\CreatedShipment;
use Logistics\Shipping\Application\OrderLine;
use Logistics\Shipping\Application\PaidOrder;
use Logistics\Shipping\Application\Port\Driven\ForChoosingCarriers;
use Logistics\Shipping\Application\Port\Driven\ForFindingProducts;
use Logistics\Shipping\Application\Port\Driven\ForIssuingTrackingCodes;
use Logistics\Shipping\Application\Port\Driven\ForStoringCancelledOrders;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\Port\Driving\ForCreatingShipments;
use Logistics\Shipping\Application\ShipmentSkipped;
use Logistics\Shipping\Domain\Error\ProductNotSyncedYet;
use Logistics\Shipping\Domain\Parcel\Parcel;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Product\Sku;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Time\Clock;

/**
 * The inbox mark, the shipment with its parcels and history, and
 * ShipmentCreated in the outbox commit together, or nothing does. A repeated
 * event finds the mark and changes nothing, and an order whose cancellation
 * came first (a replay from the dead letter topic) does not ship.
 */
#[UseCase('UC-SHP-01')]
final readonly class CreateShipment implements ForCreatingShipments
{
    private const string CONSUMER = 'logistics.order-intake';

    public function __construct(
        private ForRunningTransactions $transactions,
        private ForDeduplicatingMessages $inbox,
        private ForStoringCancelledOrders $cancelledOrders,
        private ForFindingProducts $products,
        private ForChoosingCarriers $carriers,
        private ForIssuingTrackingCodes $trackingCodes,
        private ForStoringShipments $shipments,
        private ForPublishingEvents $events,
        private Clock $clock,
    ) {}

    public function create(PaidOrder $order): CreatedShipment|ShipmentSkipped
    {
        return $this->transactions->run(function () use ($order): CreatedShipment|ShipmentSkipped {
            if (!$this->inbox->firstTime(self::CONSUMER, $order->eventId)) {
                return ShipmentSkipped::Repeated;
            }
            if ($this->cancelledOrders->has($order->orderId)) {
                return ShipmentSkipped::OrderCancelled;
            }

            return $this->ship($order);
        });
    }

    private function ship(PaidOrder $order): CreatedShipment
    {
        $parcels = $this->parcelsOf($order->lines);
        $carrier = $this->carriers->choose($order->origin, $order->destination, $parcels);
        $reference = new ShipmentReference(ShipmentId::generate(), $this->trackingCodes->next(), $order->orderId);

        $shipment = Shipment::create($reference, $carrier, $order->origin, $order->recipient, $order->destination, $parcels, $this->clock->now());
        $this->shipments->add($shipment);
        $this->events->publish(...$shipment->releaseEvents());

        return new CreatedShipment($reference, $carrier);
    }

    /**
     * One parcel per order line, measured from the local copy of the catalog.
     *
     * @param list<OrderLine> $lines
     */
    private function parcelsOf(array $lines): Parcels
    {
        $products = $this->products->bySku(...array_map(static fn(OrderLine $line): Sku => $line->sku, $lines));

        return Parcels::of(...array_map(static function (OrderLine $line) use ($products): Parcel {
            $product = $products[(string) $line->sku] ?? throw ProductNotSyncedYet::sku($line->sku);

            return $product->packed($line->quantity);
        }, $lines));
    }
}
