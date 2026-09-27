<?php

declare(strict_types=1);

namespace Tests\Doubles\CarrierSelection;

use Logistics\CarrierSelection\Application\Port\Driven\ForLocatingFulfillmentCenters;
use Logistics\CarrierSelection\Domain\UnknownFulfillmentCenter;

/** GRU1 in São Paulo and BHZ1 in Minas Gerais, like database/seeders/FulfillmentCenterSeeder. */
final readonly class InMemoryFulfillmentCenters implements ForLocatingFulfillmentCenters
{
    private const array STATES = ['GRU1' => 'SP', 'BHZ1' => 'MG'];

    public function stateOf(string $center): string
    {
        return self::STATES[$center] ?? throw UnknownFulfillmentCenter::withCode($center);
    }
}
