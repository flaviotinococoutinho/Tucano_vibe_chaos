<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Messaging\LazyProducer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\Messaging\Kafka\DeliveryFailed;
use Tucano\Messaging\Kafka\InMemoryProducer;
use Tucano\Messaging\Kafka\Message;
use Tucano\Messaging\Kafka\Producer;

final class LazyProducerTest extends TestCase
{
    private InMemoryProducer $kafka;

    private int $created = 0;

    protected function setUp(): void
    {
        $this->kafka = new InMemoryProducer();
    }

    #[Test]
    public function nothing_is_created_before_the_first_message(): void
    {
        $this->lazyProducer()->flush();

        self::assertSame(0, $this->created);
    }

    #[Test]
    public function the_first_message_creates_the_producer_once(): void
    {
        $producer = $this->lazyProducer();

        $producer->send(self::message('a'));
        $producer->send(self::message('b'));
        $producer->flush();

        self::assertSame(1, $this->created);
        self::assertCount(2, $this->kafka->delivered());
    }

    #[Test]
    public function a_failed_delivery_reaches_the_caller(): void
    {
        $this->kafka->failWith('Local: Broker transport failure');
        $producer = $this->lazyProducer();
        $producer->send(self::message('a'));

        $this->expectException(DeliveryFailed::class);

        $producer->flush();
    }

    private function lazyProducer(): LazyProducer
    {
        return new LazyProducer(function (): Producer {
            $this->created++;

            return $this->kafka;
        });
    }

    private static function message(string $key): Message
    {
        return new Message('catalog.products.v1', $key, '{}');
    }
}
