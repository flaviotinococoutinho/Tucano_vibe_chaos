<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Ordering\Domain\Order\OrderId;

/** Money back for an order that will not happen after all; Payments sends it to the provider on its own time. */
interface ForRefundingOrders
{
    public function refund(OrderId $order): void;
}
