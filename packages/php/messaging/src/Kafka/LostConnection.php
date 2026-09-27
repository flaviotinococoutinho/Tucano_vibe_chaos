<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use PDOException;
use Throwable;

/**
 * Whether a failure means the database connection was lost: the server restarted
 * or failed over, a proxy reset the socket, the network dropped. Retrying the same
 * message is the right answer, because the next message would fail the same way,
 * and a new connection comes with the next attempt.
 */
final class LostConnection
{
    /** Besides the whole SQLSTATE class 08 (connection exception): the server shutting down or not accepting yet. */
    private const array SHUTDOWN_CODES = ['57P01', '57P02', '57P03'];

    /** What pdo_pgsql reports under HY000 when the socket is already gone. */
    private const array MESSAGES = [
        'server closed the connection unexpectedly',
        'no connection to the server',
        'SSL SYSCALL error',
        'terminating connection due to administrator command',
        'could not connect to server',
    ];

    private function __construct() {}

    public static function causedBy(Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && self::isLostConnection($cause)) {
                return true;
            }
        }

        return false;
    }

    private static function isLostConnection(PDOException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;
        $state = is_string($state) ? $state : (string) $exception->getCode();
        if (str_starts_with($state, '08') || in_array($state, self::SHUTDOWN_CODES, true)) {
            return true;
        }
        foreach (self::MESSAGES as $message) {
            if (str_contains($exception->getMessage(), $message)) {
                return true;
            }
        }

        return false;
    }
}
