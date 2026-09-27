<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Messaging;

use DateTimeImmutable;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Messaging\InvalidCloudEvent;
use Tucano\SharedKernel\Tests\Doubles\OrderId;
use Tucano\SharedKernel\Tests\Doubles\OrderPaid;

#[CoversClass(CloudEvent::class)]
#[CoversClass(InvalidCloudEvent::class)]
final class CloudEventTest extends TestCase
{
    private const string SCHEMA = __DIR__ . '/../../../../../contracts/events/cloudevent.schema.json';

    #[Test]
    public function it_wraps_a_domain_event(): void
    {
        $event = $this->orderPaid();

        $envelope = CloudEvent::fromDomainEvent($event, '/commerce', 'req-1#1', 'cause-1')->toArray();

        self::assertSame('1.0', $envelope['specversion']);
        self::assertSame($event->eventId(), $envelope['id']);
        self::assertSame('tucano.commerce.order.paid', $envelope['type']);
        self::assertSame($event->aggregateId(), $envelope['subject']);
        self::assertSame('2026-09-27T12:00:04.501Z', $envelope['time']);
        self::assertSame('req-1#1', $envelope['correlationid']);
        self::assertSame('cause-1', $envelope['causationid']);
        self::assertSame($event->payload(), $envelope['data']);
    }

    #[Test]
    public function it_survives_a_round_trip_through_json(): void
    {
        $original = CloudEvent::fromDomainEvent($this->orderPaid(), '/commerce', 'req-1#1');

        $restored = CloudEvent::fromJson($original->toJson());

        self::assertSame($original->toArray(), $restored->toArray());
        self::assertArrayNotHasKey('causationid', $restored->toArray());
    }

    #[Test]
    public function it_matches_the_published_contract(): void
    {
        if (!is_file(self::SCHEMA)) {
            self::markTestSkipped('contracts/ is not available outside the monorepo checkout.');
        }
        $json = CloudEvent::fromDomainEvent($this->orderPaid(), '/commerce', 'req-1#1', 'cause-1')->toJson();
        $validator = new Validator();
        $validator->resolver()?->registerFile('urn:tucano:cloudevent', self::SCHEMA);

        $result = $validator->validate(json_decode($json), 'urn:tucano:cloudevent');

        self::assertTrue($result->isValid(), (string) json_encode($result->error()?->args()));
    }

    #[Test]
    public function it_refuses_other_spec_versions(): void
    {
        $payload = CloudEvent::fromDomainEvent($this->orderPaid(), '/commerce', 'req-1#1')->toArray();
        $payload['specversion'] = '0.3';

        $this->expectException(InvalidCloudEvent::class);

        CloudEvent::fromArray($payload);
    }

    #[Test]
    public function it_refuses_events_without_a_correlation_id(): void
    {
        $payload = CloudEvent::fromDomainEvent($this->orderPaid(), '/commerce', 'req-1#1')->toArray();
        unset($payload['correlationid']);

        $this->expectExceptionObject(InvalidCloudEvent::missing('correlationid'));

        CloudEvent::fromArray($payload);
    }

    #[Test]
    public function it_refuses_a_time_that_is_not_an_instant(): void
    {
        $payload = CloudEvent::fromDomainEvent($this->orderPaid(), '/commerce', 'req-1#1')->toArray();
        $payload['time'] = 'yesterday at teatime';

        $this->expectExceptionObject(InvalidCloudEvent::malformed('time', 'yesterday at teatime'));

        CloudEvent::fromArray($payload);
    }

    private function orderPaid(): OrderPaid
    {
        return new OrderPaid(
            '01926f3a-8c1e-7b2d-9f4a-3c5e6d7f8a9b',
            OrderId::generate()->toString(),
            new DateTimeImmutable('2026-09-27T09:00:04.501-03:00'),
        );
    }
}
