<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driven;

use Commerce\Inventory\Application\Port\Driven\ForHoldingStock;
use Illuminate\Database\ConnectionInterface;

/**
 * The default strategy: check and take in a single statement. The UPDATE locks
 * the row, and under READ COMMITTED PostgreSQL evaluates the WHERE again once a
 * concurrent writer commits, so two buyers of the last unit cannot both win.
 */
final readonly class AtomicStockHolder implements ForHoldingStock
{
    public function __construct(private ConnectionInterface $connection) {}

    public function hold(string $fulfillmentCenter, string $sku, int $quantity): bool
    {
        return $this->connection->update(<<<'SQL'
            UPDATE stock_items
               SET reserved = reserved + ?, updated_at = now()
             WHERE sku = ? AND fulfillment_center = ? AND on_hand - reserved >= ?
            SQL, [$quantity, $sku, $fulfillmentCenter, $quantity]) === 1;
    }
}
