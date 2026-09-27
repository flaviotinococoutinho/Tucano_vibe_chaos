<?php

declare(strict_types=1);

namespace Logistics\Shared\Adapter\Driven;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Logistics\Shared\Application\Isolation;
use Logistics\Shared\Application\Port\Driven\ForRunningTransactions;
use PDOException;
use Throwable;

/**
 * Laravel opens a savepoint (SAVEPOINT trans2, trans3...) when a transaction is
 * already running. The retry of serialization failures lives here, not in
 * Laravel: when the failure happens inside a savepoint, Laravel rethrows it as a
 * DeadlockException without the SQLSTATE, and its own retry does not see it.
 */
final readonly class LaravelTransactions implements ForRunningTransactions
{
    private const string SERIALIZATION_FAILURE = '40001';

    public function __construct(private ConnectionInterface $connection) {}

    public function run(Closure $work, Isolation $isolation = Isolation::ReadCommitted): mixed
    {
        if ($this->connection->transactionLevel() > 0) {
            return $this->connection->transaction(static fn(): mixed => $work());
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->connection->transaction(function () use ($work, $isolation): mixed {
                    if ($isolation !== Isolation::ReadCommitted) {
                        // Must be the first statement of the transaction.
                        $this->connection->statement('SET TRANSACTION ISOLATION LEVEL ' . $isolation->sql());
                    }

                    return $work();
                });
            } catch (Throwable $failure) {
                if ($attempt >= $isolation->attempts() || !self::isSerializationFailure($failure)) {
                    throw $failure;
                }
            }
        }
    }

    private static function isSerializationFailure(Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && ($cause->errorInfo[0] ?? null) === self::SERIALIZATION_FAILURE) {
                return true;
            }
        }

        return false;
    }
}
