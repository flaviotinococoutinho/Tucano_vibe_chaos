<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driven;

use DateTimeImmutable;

/** Payments' view of what an order does with the outcome of its payment. */
interface ForSettlingOrders
{
    /** False when the order stopped waiting before the money came, and the money must go back. */
    public function markPaid(string $orderId, DateTimeImmutable $paidAt): bool;

    public function cancelDeclined(string $orderId, DateTimeImmutable $at): void;
}
