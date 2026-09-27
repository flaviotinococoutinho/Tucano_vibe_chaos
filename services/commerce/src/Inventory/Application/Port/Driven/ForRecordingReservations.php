<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\Port\Driven;

use Commerce\Inventory\Application\StockItem;
use DateTimeImmutable;

interface ForRecordingReservations
{
    /** @param non-empty-list<StockItem> $items */
    public function record(string $orderId, string $fulfillmentCenter, array $items, DateTimeImmutable $expiresAt): void;

    /** Marks the active reservations of the order as released and gives their units back. */
    public function release(string $orderId): void;

    /** Marks the active reservations of the order as committed and takes their units off the shelf. */
    public function commit(string $orderId): void;
}
