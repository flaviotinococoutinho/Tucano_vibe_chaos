<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Kafka;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\Messaging\Kafka\IncomingEvent;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\Messaging\Tests\Doubles\Events;

#[CoversClass(IncomingEvent::class)]
final class IncomingEventTest extends TestCase
{
    #[Test]
    public function a_cloud_event_is_read_as_one(): void
    {
        $event = Events::orderPaid();

        self::assertSame($event->toArray(), IncomingEvent::read(self::record((string) json_encode($event->toArray())))->toArray());
    }

    #[Test]
    public function anything_else_is_unreadable_and_says_where_it_came_from(): void
    {
        $valid = Events::orderPaid()->toArray();

        foreach ([
            'not JSON' => '{"id":',
            'not a CloudEvent' => '{"hello":"world"}',
            'a time that is not an instant' => (string) json_encode(['time' => 'soon'] + $valid),
        ] as $case => $payload) {
            try {
                IncomingEvent::read(self::record($payload));
                self::fail(sprintf('%s should be unreadable.', $case));
            } catch (PermanentFailure $unreadable) {
                self::assertStringStartsWith('Unreadable event at commerce.orders.v1[2]@41: ', $unreadable->getMessage(), $case);
                self::assertNotNull($unreadable->getPrevious(), $case);
            }
        }
    }

    private static function record(string $payload): ReceivedMessage
    {
        return new ReceivedMessage('commerce.orders.v1', 2, 41, 'order-1', $payload, []);
    }
}
