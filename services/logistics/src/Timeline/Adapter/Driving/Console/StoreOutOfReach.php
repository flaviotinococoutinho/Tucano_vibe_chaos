<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driving\Console;

use Aws\Exception\AwsException;
use MongoDB\Driver\Exception\ConnectionException;
use Throwable;

/**
 * Whether a failure means the store of a read model is out of reach. Every message would
 * fail the same way, so the partition waits for the store instead of sending good messages
 * to the dead letter topic, as it already does for a lost database (ADR 0017).
 */
final class StoreOutOfReach
{
    private function __construct() {}

    /** MongoDB refused, dropped or never answered: no server to select, a socket gone. */
    public static function mongo(Throwable $failure): bool
    {
        return self::anyCause($failure, static fn(Throwable $cause): bool => $cause instanceof ConnectionException);
    }

    /** DynamoDB gave no answer at all: refused, reset or timed out. An answer with an error is not an outage. */
    public static function dynamo(Throwable $failure): bool
    {
        return self::anyCause($failure, static fn(Throwable $cause): bool => $cause instanceof AwsException && $cause->isConnectionError());
    }

    /** @param callable(Throwable): bool $matches */
    private static function anyCause(Throwable $failure, callable $matches): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($matches($cause)) {
                return true;
            }
        }

        return false;
    }
}
