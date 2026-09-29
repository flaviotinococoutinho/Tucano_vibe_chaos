<?php

declare(strict_types=1);

namespace Tests\Unit\Timeline;

use Aws\Command;
use Aws\Exception\AwsException;
use Logistics\Timeline\Adapter\Driving\Console\StoreOutOfReach;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Which failures make a projector wait for its store without limit, instead of filling the dead letter topic. */
final class StoreOutOfReachTest extends TestCase
{
    #[Test]
    public function mongodb_out_of_reach_makes_the_timeline_wait(): void
    {
        $outage = new ConnectionTimeoutException("No suitable servers found (`serverSelectionTryOnce` set): [connection refused calling hello on 'toxiproxy:17017']");

        self::assertTrue(StoreOutOfReach::mongo($outage));
        self::assertTrue(StoreOutOfReach::mongo(new RuntimeException('projection failed', 0, $outage)), 'a wrapped outage counts too');
        self::assertFalse(StoreOutOfReach::mongo(new BulkWriteException('E11000 duplicate key error')), 'an answer with an error is not an outage');
        self::assertFalse(StoreOutOfReach::mongo(new RuntimeException('unexpected')));
    }

    #[Test]
    public function dynamodb_with_no_answer_makes_the_page_wait(): void
    {
        $noAnswer = new AwsException('cURL error 7: Failed to connect to toxiproxy:14566', new Command('UpdateItem'), ['connection_error' => true]);
        $answered = new AwsException('Throughput exceeded', new Command('UpdateItem'), ['connection_error' => false, 'code' => 'ProvisionedThroughputExceededException']);

        self::assertTrue(StoreOutOfReach::dynamo($noAnswer));
        self::assertFalse(StoreOutOfReach::dynamo($answered), 'an answer with an error is not an outage');
        self::assertFalse(StoreOutOfReach::dynamo(new ConnectionTimeoutException('No suitable servers found')), 'each store waits for its own outage');
    }
}
