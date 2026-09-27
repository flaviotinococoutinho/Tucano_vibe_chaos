<?php

declare(strict_types=1);

namespace Tests\Doubles\Shared;

use Closure;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;

/** Runs the work as it is. Rollbacks are covered by the integration tests against PostgreSQL. */
final readonly class DirectTransactions implements ForRunningTransactions
{
    public function run(Closure $work): mixed
    {
        return $work();
    }
}
