<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Application\OrderDetails;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\OrderId;

interface ForViewingOrders
{
    /**
     * The order as it is now, with its history.
     *
     * @throws OrderNotFound
     */
    public function viewOrder(OrderId $id): OrderDetails;

    /**
     * Like viewOrder(), for the customer who placed it only.
     *
     * @throws OrderNotFound also when another customer placed the order, so the two cannot be told apart
     */
    public function viewCustomerOrder(CustomerId $customer, OrderId $id): OrderDetails;
}
