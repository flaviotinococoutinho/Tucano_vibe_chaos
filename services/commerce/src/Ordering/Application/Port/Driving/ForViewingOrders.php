<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Application\OrderDetails;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\OrderId;

interface ForViewingOrders
{
    /** @throws OrderNotFound */
    public function viewOrder(OrderId $id): OrderDetails;
}
