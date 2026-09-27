<?php

declare(strict_types=1);

namespace Tests\Doubles\Payments;

use Commerce\Payments\Application\Port\Driven\ForSettlingOrders;
use DateTimeImmutable;

/** Orders that take every payment, until the test says they stopped waiting. */
final class RecordedOrderSettlements implements ForSettlingOrders
{
    /** @var list<string> the order ids marked paid */
    public private(set) array $paid = [];

    /** @var list<string> the order ids cancelled for a declined payment */
    public private(set) array $cancelled = [];

    private bool $stoppedWaiting = false;

    public function stopWaiting(): void
    {
        $this->stoppedWaiting = true;
    }

    public function markPaid(string $orderId, DateTimeImmutable $paidAt): bool
    {
        if ($this->stoppedWaiting) {
            return false;
        }
        $this->paid[] = $orderId;

        return true;
    }

    public function cancelDeclined(string $orderId, DateTimeImmutable $at): void
    {
        $this->cancelled[] = $orderId;
    }
}
