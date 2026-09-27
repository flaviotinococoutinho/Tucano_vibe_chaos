<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\UseCase;

use Commerce\Ordering\Application\OrderDetails;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Application\Port\Driving\ForViewingOrders;
use Commerce\Ordering\Domain\Order\OrderId;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * Reads the write model on purpose: whoever just placed an order must see it
 * at once (read-your-writes), before any projection catches up.
 */
#[UseCase('UC-ORD-05')]
final readonly class ViewOrder implements ForViewingOrders
{
    public function __construct(private ForStoringOrders $orders) {}

    public function viewOrder(OrderId $id): OrderDetails
    {
        return OrderDetails::of($this->orders->get($id)->toSnapshot());
    }
}
