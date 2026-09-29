<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Console;

use MongoDB\Driver\Exception\ConnectionException;
use Throwable;

/**
 * Whether a failure means the store of a read model is out of reach. Every message would
 * fail the same way, so the partition waits for the store instead of sending good messages
 * to the dead letter topic, as it already does for a lost database (ADR 0017, ADR 0027).
 */
final class StoreOutOfReach
{
    private function __construct() {}

    /** MongoDB refused, dropped or never answered: no server to select, a socket gone. */
    public static function mongo(Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectionException) {
                return true;
            }
        }

        return false;
    }
}
