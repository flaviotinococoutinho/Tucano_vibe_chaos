<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Ordering\Application\CatalogSnapshot;

interface ForStoringCatalogCopies
{
    /**
     * Stores the snapshot unless the copy already has this version or a newer one,
     * in a single atomic step. Returns whether anything changed. Two exceptions, both
     * about the store, which never changes once a product has one (ADR 0031): the same
     * version fills in a store the copy did not know yet, and a newer snapshot without
     * a store keeps the one the copy knows.
     */
    public function saveIfNewer(CatalogSnapshot $snapshot): bool;
}
