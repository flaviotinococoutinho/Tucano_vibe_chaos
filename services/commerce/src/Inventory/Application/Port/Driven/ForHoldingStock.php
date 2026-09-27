<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\Port\Driven;

/** The reservation strategy: how a unit of stock is taken without selling it twice. */
interface ForHoldingStock
{
    /** False when the center does not have that many units free. */
    public function hold(string $fulfillmentCenter, string $sku, int $quantity): bool;
}
