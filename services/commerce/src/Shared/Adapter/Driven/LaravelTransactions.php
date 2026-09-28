<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driven;

use Closure;
use Commerce\Shared\Application\Isolation;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Illuminate\Database\ConnectionInterface;
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

    /** @param int $serializableAttempts a serializable transaction refused with 40001 runs again from the start, up to this many times in all */
    public function __construct(private ConnectionInterface $connection, private int $serializableAttempts) {}

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
                if ($attempt >= $this->attemptsFor($isolation) || !self::isSerializationFailure($failure)) {
                    throw $failure;
                }
            }
        }
    }

    /** Only serializable transactions are refused for the anomalies they would see; READ COMMITTED runs once. */
    private function attemptsFor(Isolation $isolation): int
    {
        return $isolation === Isolation::Serializable ? $this->serializableAttempts : 1;
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
