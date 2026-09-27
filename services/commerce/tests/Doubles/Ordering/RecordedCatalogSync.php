<?php

declare(strict_types=1);

namespace Tests\Doubles\Ordering;

use Commerce\Ordering\Application\CatalogSnapshot;
use Commerce\Ordering\Application\CopyOutcome;
use Commerce\Ordering\Application\Port\Driving\ForSyncingCatalog;

final class RecordedCatalogSync implements ForSyncingCatalog
{
    /** @var list<CatalogSnapshot> */
    public private(set) array $snapshots = [];

    public function sync(CatalogSnapshot $snapshot): CopyOutcome
    {
        $this->snapshots[] = $snapshot;

        return CopyOutcome::Updated;
    }
}
