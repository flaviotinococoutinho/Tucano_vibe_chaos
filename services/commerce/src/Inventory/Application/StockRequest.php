<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application;

use DateTimeImmutable;
use InvalidArgumentException;

/** What another module sends to reserve stock: plain values, so Inventory owns its own model. */
final readonly class StockRequest
{
    /** @var non-empty-list<StockItem> */
    public array $items;

    /** @param list<StockItem> $items */
    public function __construct(
        public string $orderId,
        array $items,
        public string $destinationState,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($items === []) {
            throw new InvalidArgumentException('A stock request needs at least one item.');
        }
        $this->items = $items;
    }
}
