<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Application\CatalogSnapshot;

interface ForStoringCatalogCopies
{
    /**
     * Stores the snapshot unless the copy already has this version or a newer one,
     * in a single atomic step. The one exception is the store: a snapshot of the same
     * version brings the store the copy still lacks, and one without a store never
     * erases the store the copy knows. Returns whether anything changed.
     */
    public function saveIfNewer(CatalogSnapshot $snapshot): bool;
}
