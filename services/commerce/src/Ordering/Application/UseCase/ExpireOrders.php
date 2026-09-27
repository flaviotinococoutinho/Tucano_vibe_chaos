<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\UseCase;

use Commerce\Ordering\Application\Port\Driven\ForReservingStock;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Application\Port\Driving\ForExpiringOrders;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Shared\Application\Port\Driven\ForPublishingEvents;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Time\Clock;

/**
 * One order per transaction: an order that fails to expire does not hold the
 * others back, and the row lock lasts only as long as that one order.
 */
#[UseCase('UC-ORD-03')]
final readonly class ExpireOrders implements ForExpiringOrders
{
    public function __construct(
        private ForRunningTransactions $transactions,
        private ForStoringOrders $orders,
        private ForReservingStock $stock,
        private ForPublishingEvents $events,
        private Clock $clock,
    ) {}

    public function expireNext(): ?OrderId
    {
        return $this->transactions->run(function (): ?OrderId {
            $now = $this->clock->now();
            $order = $this->orders->nextExpired($now);
            if ($order === null) {
                return null;
            }

            $order->cancel(CancellationReason::ReservationExpired, $now);
            $this->stock->release($order->id());
            $this->orders->save($order);
            $this->events->publish(...$order->releaseEvents());

            return $order->id();
        });
    }
}
