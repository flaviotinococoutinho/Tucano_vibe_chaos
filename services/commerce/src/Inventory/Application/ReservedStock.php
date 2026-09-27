<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application;

final readonly class ReservedStock
{
    public function __construct(public string $fulfillmentCenter) {}
}
