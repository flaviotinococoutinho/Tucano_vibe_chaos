<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driven;

use Commerce\Inventory\Application\Port\Driven\ForChoosingStrategy;
use Commerce\Inventory\Application\Port\Driven\ForHoldingStock;
use Commerce\Inventory\Application\ReservationStrategy;

/** Hands each hold to the holder of the strategy chosen for the request. */
final readonly class StrategicStockHolder implements ForHoldingStock
{
    public function __construct(
        private ForChoosingStrategy $strategies,
        private AtomicStockHolder $atomic,
        private PessimisticStockHolder $pessimistic,
        private OptimisticStockHolder $optimistic,
        private ReadThenWriteStockHolder $readThenWrite,
    ) {}

    public function hold(string $fulfillmentCenter, string $sku, int $quantity): bool
    {
        $holder = match ($this->strategies->current()) {
            ReservationStrategy::Atomic => $this->atomic,
            ReservationStrategy::Pessimistic => $this->pessimistic,
            ReservationStrategy::Optimistic => $this->optimistic,
            // Same code: only the isolation of the transaction tells them apart.
            ReservationStrategy::Serializable, ReservationStrategy::Naive => $this->readThenWrite,
        };

        return $holder->hold($fulfillmentCenter, $sku, $quantity);
    }
}
