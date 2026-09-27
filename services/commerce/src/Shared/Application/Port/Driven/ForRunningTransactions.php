<?php

declare(strict_types=1);

namespace Commerce\Shared\Application\Port\Driven;

use Closure;
use Commerce\Shared\Application\Isolation;

/**
 * The unit of work of a use case. A call inside another call becomes a
 * savepoint: the inner work can fail and roll back alone while the outer
 * transaction goes on. A savepoint lives in the isolation of the transaction
 * around it, so only the outermost call chooses the isolation.
 */
interface ForRunningTransactions
{
    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function run(Closure $work, Isolation $isolation = Isolation::ReadCommitted): mixed;
}
