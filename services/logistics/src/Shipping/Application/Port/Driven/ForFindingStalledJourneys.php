<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use DateTimeImmutable;
use Logistics\Shipping\Application\StalledJourneys;

/** An analytical read over shipments, their history and the reconciliation rounds. */
interface ForFindingStalledJourneys
{
    /** The shipments with a carrier whose last step happened before the instant, oldest first, at most $limit of them. */
    public function quietSince(DateTimeImmutable $before, int $limit): StalledJourneys;
}
