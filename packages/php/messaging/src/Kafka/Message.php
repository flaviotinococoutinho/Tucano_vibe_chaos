<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use Tucano\SharedKernel\Messaging\CloudEvent;

/** A record on its way to Kafka. */
final readonly class Message
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $topic,
        public ?string $key,
        public string $payload,
        public array $headers = [],
    ) {}

    /**
     * Structured-mode CloudEvent: the envelope is the value and the subject is
     * the key, so every event of an aggregate lands on the same partition, in order.
     */
    public static function fromCloudEvent(string $topic, CloudEvent $event): self
    {
        return new self($topic, $event->subject, $event->toJson(), [
            'content-type' => CloudEvent::CONTENT_TYPE,
            'ce_type' => $event->type,
            'correlation_id' => $event->correlationId,
        ]);
    }
}
