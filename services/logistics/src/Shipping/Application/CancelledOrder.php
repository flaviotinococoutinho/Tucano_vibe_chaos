<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Logistics\Shipping\Domain\Shipment\OrderId;

/** An order.cancelled event of a paid order, as Logistics reads it. */
final readonly class CancelledOrder
{
    public function __construct(public string $eventId, public OrderId $orderId) {}
}
