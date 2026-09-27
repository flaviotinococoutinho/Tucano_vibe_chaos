<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\Port\Driven;

use Commerce\Inventory\Domain\FulfillmentCenters;

interface ForFindingFulfillmentCenters
{
    public function all(): FulfillmentCenters;
}
