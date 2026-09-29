<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Application\OrderDetails;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Store\StoreSlug;

interface ForViewingOrders
{
    /**
     * The order as it is now, with its history.
     *
     * @throws OrderNotFound
     */
    public function viewOrder(OrderId $id): OrderDetails;

    /**
     * Like viewOrder(), only in the store the order was placed in and for the customer who placed it.
     *
     * @throws OrderNotFound also for an order of another store or of another customer, so none of them can be told apart
     */
    public function viewCustomerOrder(StoreSlug $store, CustomerId $customer, OrderId $id): OrderDetails;
}
