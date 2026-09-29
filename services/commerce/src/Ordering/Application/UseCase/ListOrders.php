<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\UseCase;

use Commerce\Ordering\Application\CustomerOrders;
use Commerce\Ordering\Application\Page;
use Commerce\Ordering\Application\Port\Driven\ForReadingOrderViews;
use Commerce\Ordering\Application\Port\Driving\ForListingOrders;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * UC-ORD-05, the list: the customer's orders from the read model, never from the
 * orders database. It may lag the orders by the few seconds the projection takes
 * (BASE, ADR 0012); a single order is read from PostgreSQL (ViewOrder), current.
 */
#[UseCase('UC-ORD-05')]
final readonly class ListOrders implements ForListingOrders
{
    public function __construct(private ForReadingOrderViews $views) {}

    public function listOrders(CustomerId $customer, Page $page): CustomerOrders
    {
        return $this->views->page($customer, $page);
    }
}
