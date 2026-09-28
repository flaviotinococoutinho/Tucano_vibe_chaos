<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\UseCase;

use Closure;
use Commerce\Ordering\Application\FollowOutcome;
use Commerce\Ordering\Application\Port\Driven\ForRefundingOrders;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Application\Port\Driving\ForFollowingShipments;
use Commerce\Ordering\Application\ShipmentNews;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Shared\Application\Port\Driven\ForDeduplicatingMessages;
use Commerce\Shared\Application\Port\Driven\ForPublishingEvents;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * UC-ORD-04: the order tells the customer what the shipment went through, one
 * step behind Logistics. Each step is one transaction: the inbox mark, the
 * order locked, the transition and its event in the outbox. A return also asks
 * Payments for the money back, in the same transaction, so a returned order
 * never keeps the payment.
 */
#[UseCase('UC-ORD-04')]
final readonly class FollowShipment implements ForFollowingShipments
{
    private const string INBOX = 'commerce.shipment-sync';

    public function __construct(
        private ForRunningTransactions $transactions,
        private ForDeduplicatingMessages $inbox,
        private ForStoringOrders $orders,
        private ForRefundingOrders $refunds,
        private ForPublishingEvents $events,
    ) {}

    public function recordShipped(ShipmentNews $news): FollowOutcome
    {
        return $this->follow($news, static fn(Order $order) => $order->markAsShipped($news->trackingCode, $news->at));
    }

    public function recordDelivered(ShipmentNews $news): FollowOutcome
    {
        return $this->follow($news, static fn(Order $order) => $order->markAsDelivered($news->at));
    }

    public function recordReturned(ShipmentNews $news): FollowOutcome
    {
        return $this->follow($news, function (Order $order) use ($news): void {
            $order->markAsReturned($news->at);
            $this->refunds->refund($order->id());
        });
    }

    /** @param Closure(Order): void $step */
    private function follow(ShipmentNews $news, Closure $step): FollowOutcome
    {
        return $this->transactions->run(function () use ($news, $step): FollowOutcome {
            if (!$this->inbox->firstTime(self::INBOX, $news->eventId)) {
                return FollowOutcome::Duplicate;
            }
            $order = $this->orders->lock($news->orderId);
            $step($order);
            $this->orders->save($order);
            $this->events->publish(...$order->releaseEvents());

            return FollowOutcome::Applied;
        });
    }
}
