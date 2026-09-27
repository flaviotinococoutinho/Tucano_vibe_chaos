<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;

interface ForStoringOrders
{
    public function add(Order $order): void;

    /** @throws OrderNotFound */
    public function get(OrderId $id): Order;
}
