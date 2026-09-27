<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Kafka;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\Messaging\Kafka\Message;
use Tucano\Messaging\Tests\Doubles\Events;

#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    #[Test]
    public function the_aggregate_id_becomes_the_key_so_its_events_keep_their_order(): void
    {
        $event = Events::orderPaid();

        $message = Message::fromCloudEvent('commerce.orders.v1', $event);

        self::assertSame($event->subject, $message->key);
        self::assertSame($event->toJson(), $message->payload);
        self::assertSame('application/cloudevents+json', $message->headers['content-type']);
        self::assertSame('tucano.commerce.order.paid', $message->headers['ce_type']);
        self::assertSame('req-1#1', $message->headers['correlation_id']);
    }
}
