<?php

declare(strict_types=1);

namespace Tests\Builders;

use Commerce\Ordering\Domain\Order\Order;
use RuntimeException;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Domain\DomainEvent;
use Tucano\SharedKernel\Messaging\CloudEvent;

/**
 * The events of commerce.orders.v2 as the outbox relay publishes them: built from the
 * domain events of a real Order, so the consumers are tested against what the producer sends.
 */
final class OrderEvents
{
    private static int $offset = 0;

    private function __construct() {}

    /** The one event the order recorded since the last call, as a CloudEvent in JSON. */
    public static function next(Order $order): string
    {
        $events = $order->releaseEvents();
        if (count($events) !== 1) {
            throw new RuntimeException(sprintf('Expected one event from the order, got %d.', count($events)));
        }

        return self::of($events[0]);
    }

    public static function of(DomainEvent $event): string
    {
        return CloudEvent::fromDomainEvent($event, '/commerce', 'req-30#1')->toJson();
    }

    /**
     * The same event without the names of the lines, as order.placed was published before it carried them.
     */
    public static function withoutNames(string $placed): string
    {
        /** @var array{data: array{lines: list<array<string, mixed>>}} $event */
        $event = json_decode($placed, true, flags: JSON_THROW_ON_ERROR);
        $event['data']['lines'] = array_map(static function (array $line): array {
            unset($line['name']);

            return $line;
        }, $event['data']['lines']);

        return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The same event without the store, as the events of commerce were published before the stores (ADR 0031). */
    public static function withoutStore(string $event): string
    {
        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode($event, true, flags: JSON_THROW_ON_ERROR);
        unset($decoded['data']['store']);

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $changes merged into the data of the event */
    public static function changed(string $event, array $changes): string
    {
        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode($event, true, flags: JSON_THROW_ON_ERROR);
        $decoded['data'] = [...$decoded['data'], ...$changes];

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function message(string $payload): ReceivedMessage
    {
        /** @var array{subject: string} $event */
        $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        return new ReceivedMessage('commerce.orders.v2', 0, self::$offset++, $event['subject'], $payload);
    }
}
