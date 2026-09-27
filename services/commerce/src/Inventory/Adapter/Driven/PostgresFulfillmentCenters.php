<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter\Driven;

use Commerce\Inventory\Application\Port\Driven\ForFindingFulfillmentCenters;
use Commerce\Inventory\Domain\FulfillmentCenter;
use Commerce\Inventory\Domain\FulfillmentCenters;
use Illuminate\Database\ConnectionInterface;
use stdClass;

final readonly class PostgresFulfillmentCenters implements ForFindingFulfillmentCenters
{
    public function __construct(private ConnectionInterface $connection) {}

    public function all(): FulfillmentCenters
    {
        $rows = $this->connection->select('SELECT code, state FROM fulfillment_centers ORDER BY code');

        return new FulfillmentCenters(...array_map(
            static fn(stdClass $row): FulfillmentCenter => new FulfillmentCenter((string) $row->code, (string) $row->state),
            $rows,
        ));
    }
}
