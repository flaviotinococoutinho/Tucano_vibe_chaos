<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driven;

use Commerce\Ordering\Application\Port\Driven\ForNumberingOrders;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Tucano\SharedKernel\Identity\Snowflake\SnowflakeGenerator;

final readonly class SnowflakeOrderNumbers implements ForNumberingOrders
{
    public function __construct(private SnowflakeGenerator $generator) {}

    public function next(): OrderNumber
    {
        return new OrderNumber($this->generator->next());
    }
}
