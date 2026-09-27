<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\CatalogSnapshot;
use Logistics\Shipping\Application\CopyOutcome;

interface ForSyncingCatalog
{
    public function sync(CatalogSnapshot $snapshot): CopyOutcome;
}
