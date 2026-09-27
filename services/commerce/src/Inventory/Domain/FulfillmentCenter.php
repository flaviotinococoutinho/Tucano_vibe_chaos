<?php

declare(strict_types=1);

namespace Commerce\Inventory\Domain;

final readonly class FulfillmentCenter
{
    public function __construct(public string $code, public string $state) {}
}
