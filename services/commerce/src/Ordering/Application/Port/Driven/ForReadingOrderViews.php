<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Ordering\Application\CustomerOrders;
use Commerce\Ordering\Application\Page;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderListUnavailable;

/** The customer's order list, read from the views the projection keeps. */
interface ForReadingOrderViews
{
    /** @throws OrderListUnavailable when the views cannot be read right now */
    public function page(CustomerId $customer, Page $page): CustomerOrders;
}
