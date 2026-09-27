<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\CatalogSnapshot;
use Logistics\Shipping\Application\CopyOutcome;
use Logistics\Shipping\Application\Port\Driving\ForSyncingCatalog;

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
