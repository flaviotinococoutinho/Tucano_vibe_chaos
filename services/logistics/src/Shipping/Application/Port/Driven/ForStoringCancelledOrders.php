<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use DateTimeImmutable;
use Logistics\Shipping\Domain\Shipment\OrderId;

/**
 * Paid orders cancelled before their shipment existed. Kafka keeps the events of
 * one order in order, but a message replayed from the dead letter topic comes
 * out of it: this record keeps a late order.paid from shipping a cancelled order.
 */
interface ForStoringCancelledOrders
{
    /** Recording the same order again changes nothing. */
    public function add(OrderId $order, DateTimeImmutable $cancelledAt): void;

    public function has(OrderId $order): bool;
}
