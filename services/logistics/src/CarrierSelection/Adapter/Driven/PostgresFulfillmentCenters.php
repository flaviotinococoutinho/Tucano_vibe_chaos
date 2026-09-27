<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Adapter\Driven;

use Illuminate\Database\ConnectionInterface;
use Logistics\CarrierSelection\Application\Port\Driven\ForLocatingFulfillmentCenters;
use Logistics\CarrierSelection\Domain\UnknownFulfillmentCenter;

final readonly class PostgresFulfillmentCenters implements ForLocatingFulfillmentCenters
{
    public function __construct(private ConnectionInterface $connection) {}

    public function stateOf(string $center): string
    {
        $state = $this->connection->scalar('SELECT state FROM fulfillment_centers WHERE code = ?', [$center]);

        return is_string($state) ? $state : throw UnknownFulfillmentCenter::withCode($center);
    }
}
