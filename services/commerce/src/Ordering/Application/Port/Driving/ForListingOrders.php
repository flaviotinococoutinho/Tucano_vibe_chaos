<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Application\CustomerOrders;
use Commerce\Ordering\Application\Page;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderListUnavailable;

interface ForListingOrders
{
    /**
     * A page of the customer's orders, newest first; a customer with no orders gets an empty page.
     *
     * @throws OrderListUnavailable when the list cannot be read right now
     */
    public function listOrders(CustomerId $customer, Page $page): CustomerOrders;
}
