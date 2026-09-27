<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Kafka;

use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Tucano\Messaging\Kafka\LostConnection;

#[CoversClass(LostConnection::class)]
final class LostConnectionTest extends TestCase
{
    #[Test]
    #[DataProvider('failures')]
    public function it_tells_a_lost_connection_from_any_other_failure(Throwable $failure, bool $lost): void
    {
        self::assertSame($lost, LostConnection::causedBy($failure));
    }

    /** @return iterable<string, array{Throwable, bool}> */
    public static function failures(): iterable
    {
        yield 'no connection at all (class 08)' => [self::pdo('08006', 'SQLSTATE[08006] [7] connection to server at "toxiproxy" (172.19.0.2), port 15432 failed'), true];
        yield 'the server shutting down' => [self::pdo('57P01', 'SQLSTATE[57P01]: Admin shutdown: 7 FATAL: terminating connection due to administrator command'), true];
        yield 'the socket gone, reported as a general error' => [self::pdo('HY000', 'SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly'), true];
        yield 'wrapped by the framework' => [new RuntimeException('query failed', 0, self::pdo('08006', 'SQLSTATE[08006] [7] no connection to the server')), true];
        yield 'a constraint the data broke' => [self::pdo('23505', 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value'), false];
        yield 'a deadlock, which the bounded retries handle' => [self::pdo('40P01', 'SQLSTATE[40P01]: Deadlock detected: 7 ERROR: deadlock detected'), false];
        yield 'not the database' => [new RuntimeException('flagd did not answer'), false];
    }

    private static function pdo(string $state, string $message): PDOException
    {
        $exception = new PDOException($message);
        $exception->errorInfo = [$state, 7, $message];

        return $exception;
    }
}
