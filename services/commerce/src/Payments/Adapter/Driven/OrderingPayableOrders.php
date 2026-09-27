<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driven;

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
        try {
            $order = $this->orders->viewOrder(OrderId::fromString($orderId));
        } catch (InvalidArgumentException) {
            throw OrderNotFound::withId($orderId);
        }
        if ($order->status() !== 'pending_payment') {
            throw OrderNotPayable::because($orderId, sprintf('it is %s', str_replace('_', ' ', $order->status())));
        }
        if ($order->reservationExpiresAt() <= $this->clock->now()) {
            throw OrderNotPayable::because($orderId, 'its reservation ran out');
        }

        return new PayableOrder($orderId, $order->total());
    }
}
