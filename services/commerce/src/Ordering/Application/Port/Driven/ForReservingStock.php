<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Inventory\Domain\InsufficientStock;
use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLines;
use DateTimeImmutable;

/** Ordering's view of Inventory: hold these lines until then, and say where they are. */
interface ForReservingStock
{
    /** @throws InsufficientStock */
    public function reserve(OrderId $order, OrderLines $lines, ShippingAddress $destination, DateTimeImmutable $until): FulfillmentCenterCode;
}
