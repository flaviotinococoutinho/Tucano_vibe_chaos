<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Commerce\Ordering\Adapter\Driving\Console\StoreOutOfReach;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Which failures make the order projector wait for MongoDB without limit, instead of filling its dead letter topic. */
final class StoreOutOfReachTest extends TestCase
{
    #[Test]
    public function mongodb_out_of_reach_makes_the_list_wait(): void
    {
        $outage = new ConnectionTimeoutException("No suitable servers found (`serverSelectionTryOnce` set): [connection refused calling hello on 'toxiproxy:17017']");

        self::assertTrue(StoreOutOfReach::mongo($outage));
        self::assertTrue(StoreOutOfReach::mongo(new RuntimeException('projection failed', 0, $outage)), 'a wrapped outage counts too');
    }

    #[Test]
    public function an_answer_with_an_error_is_not_an_outage(): void
    {
        self::assertFalse(StoreOutOfReach::mongo(new BulkWriteException('Document failed validation')));
        self::assertFalse(StoreOutOfReach::mongo(new PDOException('SQLSTATE[08006] server closed the connection unexpectedly')), 'the list waits for its own store, not for PostgreSQL');
        self::assertFalse(StoreOutOfReach::mongo(new RuntimeException('unexpected')));
    }
}
