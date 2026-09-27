<?php

declare(strict_types=1);

namespace Logistics\Shared\Adapter\Driving\Kafka;

use JsonException;
use Throwable;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Messaging\InvalidCloudEvent;

/**
 * Opens the CloudEvent in a Kafka record. A record that cannot be read will
 * never become readable, so it goes straight to the dead letter topic, with
 * where it came from in the error.
 */
final readonly class IncomingEvents
{
    private function __construct() {}

    /** @throws PermanentFailure when the record is not a CloudEvent */
    public static function read(ReceivedMessage $message): CloudEvent
    {
        try {
            return CloudEvent::fromJson($message->payload);
        } catch (InvalidCloudEvent|JsonException $invalid) {
            throw self::unreadable($message, $invalid);
        }
    }

    public static function unreadable(ReceivedMessage $message, Throwable $reason): PermanentFailure
    {
        return new PermanentFailure(
            sprintf('Unreadable event at %s[%d]@%d: %s', $message->topic, $message->partition, $message->offset, $reason->getMessage()),
            previous: $reason,
        );
    }
}
