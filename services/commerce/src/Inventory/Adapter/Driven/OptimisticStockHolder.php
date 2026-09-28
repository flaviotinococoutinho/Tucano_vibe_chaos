<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driven;

use Commerce\Inventory\Application\Port\Driven\ForHoldingStock;
use Commerce\Inventory\Domain\StockContention;
use Illuminate\Database\ConnectionInterface;

/**
 * Reads without locking and writes only if nobody changed the row since
 * (WHERE version = the version read). When someone did, it reads again. No one
 * waits on a lock, but under heavy contention most attempts are wasted, and
 * after a few rounds it gives up instead of spinning forever.
 */
final readonly class OptimisticStockHolder implements ForHoldingStock
{
    public function __construct(private ConnectionInterface $connection, private int $attempts) {}

    public function hold(string $fulfillmentCenter, string $sku, int $quantity): bool
    {
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $row = $this->connection->selectOne(
                'SELECT on_hand, reserved, version FROM stock_items WHERE sku = ? AND fulfillment_center = ?',
                [$sku, $fulfillmentCenter],
            );
            if ($row === null || (int) $row->on_hand - (int) $row->reserved < $quantity) {
                return false;
            }
            $updated = $this->connection->update(<<<'SQL'
                UPDATE stock_items
                   SET reserved = reserved + ?, version = version + 1, updated_at = now()
                 WHERE sku = ? AND fulfillment_center = ? AND version = ?
                SQL, [$quantity, $sku, $fulfillmentCenter, (int) $row->version]);
            if ($updated === 1) {
                return true;
            }
        }

        throw StockContention::on($sku, $fulfillmentCenter, $this->attempts);
    }
}
