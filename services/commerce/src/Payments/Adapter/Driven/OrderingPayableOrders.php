<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driven;

use Commerce\Ordering\Application\OrderDetails;
use Commerce\Ordering\Application\Port\Driving\ForViewingOrders;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Payments\Application\PayableOrder;
use Commerce\Payments\Application\Port\Driven\ForFindingPayableOrders;
use Commerce\Payments\Domain\OrderNotPayable;
use InvalidArgumentException;
use Tucano\SharedKernel\Time\Clock;

/**
 * A driven port of Payments answered by the driving port of Ordering, like the
 * stock reservation between Ordering and Inventory. The check here only fails
 * fast; the webhook checks the order again, under lock, before marking it paid.
 */
final readonly class OrderingPayableOrders implements ForFindingPayableOrders
{
    public function __construct(private ForViewingOrders $orders, private Clock $clock) {}

    public function payable(string $orderId): PayableOrder
    {
        $order = $this->order($orderId);
        $obstacle = $this->obstacleToPaying($order);
        if ($obstacle !== null) {
            throw OrderNotPayable::because($orderId, $obstacle);
        }

        return new PayableOrder($orderId, $order->total());
    }

    public function awaitsPayment(string $orderId): bool
    {
        return $this->obstacleToPaying($this->order($orderId)) === null;
    }

    private function order(string $orderId): OrderDetails
    {
        try {
            return $this->orders->viewOrder(OrderId::fromString($orderId));
        } catch (InvalidArgumentException) {
            throw OrderNotFound::withId($orderId);
        }
    }

    /** Why the order cannot be paid now, or null when it can. */
    private function obstacleToPaying(OrderDetails $order): ?string
    {
        if ($order->status() !== 'pending_payment') {
            return sprintf('it is %s', str_replace('_', ' ', $order->status()));
        }
        if ($order->reservationExpiresAt() <= $this->clock->now()) {
            return 'its reservation ran out';
        }

        return null;
    }
}
