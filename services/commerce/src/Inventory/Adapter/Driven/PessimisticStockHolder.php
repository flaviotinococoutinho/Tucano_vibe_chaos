<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driven;

use Commerce\Inventory\Application\Port\Driven\ForHoldingStock;
use Illuminate\Database\ConnectionInterface;

/**
 * Locks the row when reading it (SELECT ... FOR UPDATE). Every other buyer of
 * that SKU waits at the same SELECT until this transaction ends, so the check
 * and the write cannot interleave. Correct and easy to reason about; the cost
 * is that the checkout of a hot product turns into a queue.
 */
final readonly class PessimisticStockHolder implements ForHoldingStock
{
    public function __construct(private ConnectionInterface $connection) {}

    public function hold(string $fulfillmentCenter, string $sku, int $quantity): bool
    {
        $row = $this->connection->selectOne(
            'SELECT on_hand, reserved FROM stock_items WHERE sku = ? AND fulfillment_center = ? FOR UPDATE',
            [$sku, $fulfillmentCenter],
        );
        if ($row === null || (int) $row->on_hand - (int) $row->reserved < $quantity) {
            return false;
        }
        $this->connection->update(
            'UPDATE stock_items SET reserved = reserved + ?, updated_at = now() WHERE sku = ? AND fulfillment_center = ?',
            [$quantity, $sku, $fulfillmentCenter],
        );

        return true;
    }
}
