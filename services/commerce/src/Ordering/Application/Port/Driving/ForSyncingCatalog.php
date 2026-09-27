<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Application\CatalogSnapshot;
use Commerce\Ordering\Application\CopyOutcome;

interface ForSyncingCatalog
{
    public function sync(CatalogSnapshot $snapshot): CopyOutcome;
}
