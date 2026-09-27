<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Outbox;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\Messaging\Kafka\DeliveryFailed;
use Tucano\Messaging\Kafka\InMemoryProducer;
use Tucano\Messaging\Outbox\OutboxRelay;
use Tucano\Messaging\Outbox\OutboxWriter;
use Tucano\Messaging\Tests\Doubles\Events;
use Tucano\Messaging\Tests\Doubles\Postgres;

#[CoversClass(OutboxRelay::class)]
#[CoversClass(OutboxWriter::class)]
#[Group('integration')]
final class OutboxRelayTest extends TestCase
{
    private PDO $connection;
    private InMemoryProducer $kafka;

    protected function setUp(): void
    {
        $this->connection = Postgres::connect();
        $this->kafka = new InMemoryProducer();
    }

    #[Test]
    public function an_event_written_in_a_rolled_back_transaction_is_never_published(): void
    {
        $this->connection->beginTransaction();
        (new OutboxWriter($this->connection))->append('commerce.orders.v1', Events::orderPaid());
        $this->connection->rollBack();

        self::assertSame(0, (new OutboxRelay($this->connection, $this->kafka))->relayBatch());
    }

    #[Test]
    public function committed_events_are_published_once(): void
    {
        $event = Events::orderPaid();
        (new OutboxWriter($this->connection))->append('commerce.orders.v1', $event);
        $relay = new OutboxRelay($this->connection, $this->kafka);

        self::assertSame(1, $relay->relayBatch());
        self::assertSame(0, $relay->relayBatch());
        self::assertSame($event->subject, $this->kafka->delivered()[0]->key);
        self::assertSame('req-1#1', $this->kafka->delivered()[0]->headers['correlation_id']);
    }

    #[Test]
    public function when_kafka_is_down_the_events_wait_for_the_next_run(): void
    {
        (new OutboxWriter($this->connection))->append('commerce.orders.v1', Events::orderPaid());
        $relay = new OutboxRelay($this->connection, $this->kafka);
        $this->kafka->failWith('all brokers down');

        try {
            $relay->relayBatch();
            self::fail('the relay should rethrow the delivery failure');
        } catch (DeliveryFailed) {
            self::assertSame(1, $this->pendingWithOneFailedAttempt());
        }

        $this->kafka->recover();
        self::assertSame(1, $relay->relayBatch());
    }

    private function pendingWithOneFailedAttempt(): int
    {
        $statement = $this->connection->query(
            "SELECT count(*) FROM outbox_messages WHERE published_at IS NULL AND attempts = 1 AND last_error LIKE '%brokers down%'",
        );

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }
}
