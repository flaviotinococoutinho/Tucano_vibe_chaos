<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\UseCase;

use Commerce\Ordering\Application\CatalogSnapshot;
use Commerce\Ordering\Application\CopyOutcome;
use Commerce\Ordering\Application\Port\Driven\ForStoringCatalogCopies;
use Commerce\Ordering\Application\Port\Driving\ForSyncingCatalog;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * The copy only moves forward: a repeated or late snapshot (at-least-once
 * delivery, a consumer reading the topic from the start) is ignored by version,
 * so this use case needs no inbox.
 */
#[UseCase('UC-ORD-06')]
final readonly class SyncCatalogProduct implements ForSyncingCatalog
{
    public function __construct(private ForStoringCatalogCopies $copies) {}

    public function sync(CatalogSnapshot $snapshot): CopyOutcome
    {
        return $this->copies->saveIfNewer($snapshot) ? CopyOutcome::Updated : CopyOutcome::Stale;
    }
}
