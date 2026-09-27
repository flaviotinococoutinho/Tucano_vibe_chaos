<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shared\Application\Port\Driven\ForDeduplicatingMessages;
use Logistics\Shared\Application\Port\Driven\ForPublishingEvents;
use Logistics\Shared\Application\Port\Driven\ForRunningTransactions;
use Logistics\Shipping\Application\CancellationOutcome;
use Logistics\Shipping\Application\CancelledOrder;
use Logistics\Shipping\Application\Port\Driven\ForStoringCancelledOrders;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\Port\Driving\ForCancellingShipments;
use Logistics\Shipping\Domain\Shipment\CancellationReason;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Time\Clock;

/**
 * Stops the shipment of a cancelled order while the parcels are still in the
 * warehouse. After pickup the state machine refuses, and the refusal travels
 * back to the caller: a person has to bring those parcels back. An order with
 * no shipment yet is remembered, so its payment does not ship it later.
 */
#[UseCase('UC-SHP-09')]
final readonly class CancelShipment implements ForCancellingShipments
{
    private const string CONSUMER = 'logistics.order-intake';

    public function __construct(
        private ForRunningTransactions $transactions,
        private ForDeduplicatingMessages $inbox,
        private ForStoringShipments $shipments,
        private ForStoringCancelledOrders $cancelledOrders,
        private ForPublishingEvents $events,
        private Clock $clock,
    ) {}

    public function cancel(CancelledOrder $order): CancellationOutcome
    {
        return $this->transactions->run(
            fn(): CancellationOutcome => $this->inbox->firstTime(self::CONSUMER, $order->eventId) ? $this->stop($order) : CancellationOutcome::Repeated,
        );
    }

    private function stop(CancelledOrder $order): CancellationOutcome
    {
        $shipment = $this->shipments->forOrder($order->orderId);
        if ($shipment === null) {
            $this->cancelledOrders->add($order->orderId, $this->clock->now());

            return CancellationOutcome::NoShipment;
        }

        $shipment->cancel(CancellationReason::OrderCancelled, $this->clock->now());
        $this->shipments->save($shipment);
        $this->events->publish(...$shipment->releaseEvents());

        return CancellationOutcome::Cancelled;
    }
}
