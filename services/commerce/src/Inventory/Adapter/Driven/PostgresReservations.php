<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driven;

use Commerce\Inventory\Application\Port\Driven\ForRecordingReservations;
use Commerce\Inventory\Application\StockItem;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Tucano\SharedKernel\Time\Clock;

final readonly class PostgresReservations implements ForRecordingReservations
{
    public function __construct(private ConnectionInterface $connection, private Clock $clock) {}

    public function record(string $orderId, string $fulfillmentCenter, array $items, DateTimeImmutable $expiresAt): void
    {
        $now = $this->clock->now()->format(DATE_RFC3339_EXTENDED);
        $this->connection->table('stock_reservations')->insert(array_map(static fn(StockItem $item): array => [
            'id' => Uuid::uuid7()->toString(),
            'order_id' => $orderId,
            'sku' => $item->sku,
            'fulfillment_center' => $fulfillmentCenter,
            'quantity' => $item->quantity,
            'status' => 'active',
            'expires_at' => $expiresAt->format(DATE_RFC3339_EXTENDED),
            'created_at' => $now,
        ], $items));
    }

    public function release(string $orderId): void
    {
        // One statement: the reservations change state and the units go back together, or neither happens.
        $this->connection->affectingStatement(<<<'SQL'
            WITH released AS (
                UPDATE stock_reservations SET status = 'released'
                 WHERE order_id = ? AND status = 'active'
             RETURNING sku, fulfillment_center, quantity
            )
            UPDATE stock_items AS stock
               SET reserved = stock.reserved - released.quantity, updated_at = now()
              FROM released
             WHERE stock.sku = released.sku AND stock.fulfillment_center = released.fulfillment_center
            SQL, [$orderId]);
    }

    public function commit(string $orderId): void
    {
        // A sale lowers on_hand and reserved together, so reserved <= on_hand keeps holding.
        $this->connection->affectingStatement(<<<'SQL'
            WITH committed AS (
                UPDATE stock_reservations SET status = 'committed'
                 WHERE order_id = ? AND status = 'active'
             RETURNING sku, fulfillment_center, quantity
            )
            UPDATE stock_items AS stock
               SET on_hand = stock.on_hand - committed.quantity,
                   reserved = stock.reserved - committed.quantity,
                   updated_at = now()
              FROM committed
             WHERE stock.sku = committed.sku AND stock.fulfillment_center = committed.fulfillment_center
            SQL, [$orderId]);
    }
}
