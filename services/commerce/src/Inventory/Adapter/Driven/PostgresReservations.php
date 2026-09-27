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
}
