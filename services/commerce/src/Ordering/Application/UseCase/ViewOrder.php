<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\UseCase;

use Commerce\Ordering\Application\OrderDetails;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Application\Port\Driving\ForViewingOrders;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Store\StoreSlug;
use Commerce\Shared\Application\Isolation;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * Reads the write model on purpose: whoever just placed an order must see it
 * at once (read-your-writes), before any projection catches up. The order and
 * its history come from one snapshot, so the status and the last step of the
 * history always agree, even while the order moves.
 */
#[UseCase('UC-ORD-05')]
final readonly class ViewOrder implements ForViewingOrders
{
    public function __construct(private ForRunningTransactions $transactions, private ForStoringOrders $orders) {}

    public function viewOrder(OrderId $id): OrderDetails
    {
        return $this->transactions->run(fn(): OrderDetails => $this->detailsOf($this->orders->get($id)), Isolation::RepeatableRead);
    }

    public function viewCustomerOrder(StoreSlug $store, CustomerId $customer, OrderId $id): OrderDetails
    {
        return $this->transactions->run(function () use ($store, $customer, $id): OrderDetails {
            $order = $this->orders->get($id);
            if (!$order->isPlacedIn($store) || !$order->isPlacedBy($customer)) {
                // The same answer as an order that does not exist: nothing tells the order of another store or of another customer apart.
                throw OrderNotFound::withId($id->toString());
            }

            return $this->detailsOf($order);
        }, Isolation::RepeatableRead);
    }

    private function detailsOf(Order $order): OrderDetails
    {
        return OrderDetails::of($order->toSnapshot())->withHistory($this->orders->history($order->id()));
    }
}
