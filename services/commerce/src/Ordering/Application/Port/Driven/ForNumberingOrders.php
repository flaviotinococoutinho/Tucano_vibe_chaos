<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Ordering\Domain\Order\OrderNumber;

interface ForNumberingOrders
{
    public function next(): OrderNumber;
}
