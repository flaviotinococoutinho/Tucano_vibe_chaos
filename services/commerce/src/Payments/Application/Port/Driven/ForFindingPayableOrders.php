<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driven;

use Commerce\Payments\Application\PayableOrder;
use Commerce\Payments\Domain\OrderNotPayable;

interface ForFindingPayableOrders
{
    /** @throws OrderNotPayable when the order does not exist, is not waiting for payment, or its reservation ran out */
    public function payable(string $orderId): PayableOrder;

    /** Whether the order can still be paid, by the same rules as payable(). */
    public function awaitsPayment(string $orderId): bool;
}
