<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Application\CustomerOrders;
use Commerce\Ordering\Application\Page;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderListUnavailable;
use Commerce\Ordering\Domain\Store\StoreSlug;

interface ForListingOrders
{
    /**
     * A page of the customer's orders in that store, newest first; a customer with no orders
     * there gets an empty page, even with orders in other stores (ADR 0031).
     *
     * @throws OrderListUnavailable when the list cannot be read right now
     */
    public function listOrders(StoreSlug $store, CustomerId $customer, Page $page): CustomerOrders;
}
