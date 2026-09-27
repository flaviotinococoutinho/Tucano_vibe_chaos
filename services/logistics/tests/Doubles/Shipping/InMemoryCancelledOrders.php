<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use DateTimeImmutable;
use Logistics\Shipping\Application\Port\Driven\ForStoringCancelledOrders;
use Logistics\Shipping\Domain\Shipment\OrderId;

final class InMemoryCancelledOrders implements ForStoringCancelledOrders
{
    /** @var array<string, DateTimeImmutable> order id => when it was cancelled */
    public private(set) array $cancelledAt = [];

    public function add(OrderId $order, DateTimeImmutable $cancelledAt): void
    {
        $this->cancelledAt[$order->toString()] ??= $cancelledAt;
    }

    public function has(OrderId $order): bool
    {
        return isset($this->cancelledAt[$order->toString()]);
    }
}
