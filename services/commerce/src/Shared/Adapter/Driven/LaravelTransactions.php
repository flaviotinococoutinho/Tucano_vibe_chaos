<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driven;

use Closure;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Illuminate\Database\ConnectionInterface;

/** Laravel opens a savepoint (SAVEPOINT trans2, trans3...) when a transaction is already running. */
final readonly class LaravelTransactions implements ForRunningTransactions
{
    public function __construct(private ConnectionInterface $connection) {}

    public function run(Closure $work): mixed
    {
        return $this->connection->transaction(static fn(): mixed => $work());
    }
}
