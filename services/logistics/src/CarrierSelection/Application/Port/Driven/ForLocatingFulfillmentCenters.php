<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Application\Port\Driven;

use Logistics\CarrierSelection\Domain\UnknownFulfillmentCenter;

interface ForLocatingFulfillmentCenters
{
    /**
     * The state (UF) where the center is.
     *
     * @throws UnknownFulfillmentCenter
     */
    public function stateOf(string $center): string;
}
