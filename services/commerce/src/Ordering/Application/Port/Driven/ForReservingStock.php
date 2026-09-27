<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Error\StockNotReserved;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Shared\Application\Isolation;
use DateTimeImmutable;

/** Ordering's view of Inventory: hold these lines until then, and say where they are. */
interface ForReservingStock
{
    /** Asked before the transaction starts: some reservation strategies need a stronger isolation. */
    public function requiredIsolation(): Isolation;

    /** @throws StockNotReserved */
    public function reserve(OrderId $order, OrderLines $lines, ShippingAddress $destination, DateTimeImmutable $until): FulfillmentCenterCode;

    public function release(OrderId $order): void;

    public function commit(OrderId $order): void;
}
