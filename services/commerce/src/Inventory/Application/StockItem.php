<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application;

final readonly class StockItem
{
    public function __construct(public string $sku, public int $quantity) {}
}
