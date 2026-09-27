<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driven;

use Commerce\Inventory\Application\Port\Driven\ForHoldingStock;
use Illuminate\Database\ConnectionInterface;

/**
 * Reads, decides in PHP and writes the new total back. The isolation of the
 * transaction around it decides what happens when two buyers race:
 *
 * - READ COMMITTED (the naive strategy): both read reserved = 4 and both write
 *   5, two reservations for one unit held. A lost update. The CHECK reserved <=
 *   on_hand never fires, because 5 is still a valid number; what breaks is the
 *   invariant "reserved = sum of the active reservations".
 * - SERIALIZABLE: PostgreSQL refuses the second write (SQLSTATE 40001) and the
 *   use case runs again from the start, now reading 5.
 */
final readonly class ReadThenWriteStockHolder implements ForHoldingStock
{
    public function __construct(private ConnectionInterface $connection) {}

    public function hold(string $fulfillmentCenter, string $sku, int $quantity): bool
    {
        $row = $this->connection->selectOne(
            'SELECT on_hand, reserved FROM stock_items WHERE sku = ? AND fulfillment_center = ?',
            [$sku, $fulfillmentCenter],
        );
        if ($row === null || (int) $row->on_hand - (int) $row->reserved < $quantity) {
            return false;
        }
        $this->connection->update(
            'UPDATE stock_items SET reserved = ?, updated_at = now() WHERE sku = ? AND fulfillment_center = ?',
            [(int) $row->reserved + $quantity, $sku, $fulfillmentCenter],
        );

        return true;
    }
}
