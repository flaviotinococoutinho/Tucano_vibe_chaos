<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Ordering\Domain\Error\OrderChangedMeanwhile;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;
use DateTimeImmutable;

interface ForStoringOrders
{
    public function add(Order $order): void;

    /** @throws OrderNotFound */
    public function get(OrderId $id): Order;

    /** @throws OrderChangedMeanwhile when the stored version is not the one this order was loaded with */
    public function save(Order $order): void;

    /**
     * The oldest unpaid order whose reservation has run out, locked for this
     * transaction. Orders another worker holds are skipped, not waited for.
     */
    public function nextExpired(DateTimeImmutable $now): ?Order;
}
