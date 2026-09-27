<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use RdKafka\Message as RdKafkaMessage;

/** A record read from Kafka, with where it came from. */
final readonly class ReceivedMessage
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $topic,
        public int $partition,
        public int $offset,
        public ?string $key,
        public string $payload,
        public array $headers = [],
    ) {}

    public static function fromRdKafka(RdKafkaMessage $message): self
    {
        $headers = [];
        // librdkafka hands over null, not an empty array, when a record has no headers.
        foreach ((array) $message->headers as $name => $value) {
            $headers[(string) $name] = (string) $value;
        }

        return new self(
            (string) $message->topic_name,
            $message->partition,
            $message->offset,
            $message->key,
            (string) $message->payload,
            $headers,
        );
    }
}
