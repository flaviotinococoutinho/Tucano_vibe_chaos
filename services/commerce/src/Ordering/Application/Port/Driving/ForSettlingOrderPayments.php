<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Application\PaymentSettlement;
use Commerce\Ordering\Domain\Order\OrderId;
use DateTimeImmutable;

/** How Payments tells an order what happened to its payment. Both run inside the caller's transaction. */
interface ForSettlingOrderPayments
{
    /** Marks the order paid and takes its stock off the shelf, or says it is too late. */
    public function markPaid(OrderId $order, DateTimeImmutable $paidAt): PaymentSettlement;

    /** The payment was declined: the order is cancelled and its stock goes back on sale. */
    public function cancelDeclined(OrderId $order, DateTimeImmutable $at): void;
}
