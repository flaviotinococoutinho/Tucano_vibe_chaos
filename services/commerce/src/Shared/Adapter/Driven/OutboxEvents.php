<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driven;

use Commerce\Shared\Application\Port\Driven\ForPublishingEvents;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Context;
use LogicException;
use Ramsey\Uuid\Uuid;
use Tucano\Messaging\Outbox\OutboxWriter;
use Tucano\SharedKernel\Domain\DomainEvent;
use Tucano\SharedKernel\Messaging\CloudEvent;

/**
 * Writes each event to the outbox through the connection of the running
 * transaction: the event is stored if, and only if, the state change is.
 * The relay publishes it to Kafka later.
 */
final readonly class OutboxEvents implements ForPublishingEvents
{
    private const string SOURCE = '/commerce';

    public function __construct(private Connection $connection) {}

    public function publish(DomainEvent ...$events): void
    {
        $outbox = new OutboxWriter($this->connection->getPdo());
        foreach ($events as $event) {
            $outbox->append(self::topicOf($event), CloudEvent::fromDomainEvent($event, self::SOURCE, self::correlationId()));
        }
    }

    private static function topicOf(DomainEvent $event): string
    {
        return match (true) {
            str_starts_with($event->eventType(), 'tucano.commerce.order.') => 'commerce.orders.v2',
            default => throw new LogicException(sprintf('No topic is mapped for %s.', $event->eventType())),
        };
    }

    /** A flow that did not come through Kong (a scheduled job, say) starts its own correlation. */
    private static function correlationId(): string
    {
        $correlationId = Context::get('correlation_id');

        return is_string($correlationId) && $correlationId !== '' ? $correlationId : Uuid::uuid7()->toString();
    }
}
