<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Ordering\Application\CustomerOrders;
use Commerce\Ordering\Application\Page;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderListUnavailable;
use Commerce\Ordering\Domain\Store\StoreSlug;

/** The customer's order list in a store, read from the views the projection keeps. */
interface ForReadingOrderViews
{
    /**
     * Only the views of that store and that customer, newest first; a view without a store is in no page.
     *
     * @throws OrderListUnavailable when the views cannot be read right now
     */
    public function page(StoreSlug $store, CustomerId $customer, Page $page): CustomerOrders;
}
