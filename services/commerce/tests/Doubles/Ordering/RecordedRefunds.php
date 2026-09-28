<?php

declare(strict_types=1);

namespace Tests\Doubles\Ordering;

use Commerce\Ordering\Application\Port\Driven\ForRefundingOrders;
use Commerce\Ordering\Domain\Order\OrderId;

final class RecordedRefunds implements ForRefundingOrders
{
    /** @var list<string> the orders whose money was asked back, in order */
    public private(set) array $orders = [];

    public function refund(OrderId $order): void
    {
        $this->orders[] = $order->toString();
    }
}
