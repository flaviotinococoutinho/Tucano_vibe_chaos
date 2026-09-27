<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Kafka;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\Messaging\Kafka\DeliveryFailed;
use Tucano\Messaging\Kafka\InMemoryProducer;
use Tucano\Messaging\Kafka\Message;

#[CoversClass(InMemoryProducer::class)]
#[CoversClass(DeliveryFailed::class)]
final class InMemoryProducerTest extends TestCase
{
    #[Test]
    public function nothing_counts_as_delivered_before_the_flush(): void
    {
        $producer = new InMemoryProducer();
        $producer->send(new Message('commerce.orders.v1', 'order-1', '{}'));

        self::assertSame([], $producer->delivered());

        $producer->flush();

        self::assertCount(1, $producer->delivered());
    }

    #[Test]
    public function an_outage_fails_the_flush(): void
    {
        $producer = new InMemoryProducer();
        $producer->failWith('all brokers down');
        $producer->send(new Message('commerce.orders.v1', 'order-1', '{}'));

        $this->expectException(DeliveryFailed::class);

        $producer->flush();
    }
}
