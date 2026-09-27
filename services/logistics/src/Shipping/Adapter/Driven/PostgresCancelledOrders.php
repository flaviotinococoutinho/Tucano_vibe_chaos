<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Shipping\Application\Port\Driven\ForStoringCancelledOrders;
use Logistics\Shipping\Domain\Shipment\OrderId;

final readonly class PostgresCancelledOrders implements ForStoringCancelledOrders
{
    public function __construct(private ConnectionInterface $connection) {}

    public function add(OrderId $order, DateTimeImmutable $cancelledAt): void
    {
        $this->connection->statement(
            'INSERT INTO cancelled_orders (order_id, cancelled_at) VALUES (?, ?) ON CONFLICT (order_id) DO NOTHING',
            [$order->toString(), $cancelledAt->format(DATE_RFC3339_EXTENDED)],
        );
    }

    public function has(OrderId $order): bool
    {
        return $this->connection->selectOne('SELECT 1 AS found FROM cancelled_orders WHERE order_id = ?', [$order->toString()]) !== null;
    }
}
