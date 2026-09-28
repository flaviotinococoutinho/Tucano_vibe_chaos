<?php

declare(strict_types=1);

namespace Tests\Doubles\Ordering;

use Commerce\Ordering\Application\Port\Driven\ForNumberingOrders;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

final class SequentialOrderNumbers implements ForNumberingOrders
{
    private const int NOON_2026_09_27 = 1_790_510_400_000;

    private int $sequence = 0;

    public function next(): OrderNumber
    {
        return OrderNumber::fromSnowflake(Snowflake::compose(self::NOON_2026_09_27, new NodeId(1, 1), $this->sequence++));
    }
}
