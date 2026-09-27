<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use JsonException;
use Throwable;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Messaging\InvalidCloudEvent;

/**
 * The first step of every handler: a Kafka record becomes a CloudEvent, or a
 * PermanentFailure that sends it straight to the dead letter topic, since
 * reading it again would fail the same way.
 */
final class IncomingEvent
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

    /** For a handler whose own reading of the data failed: the record is unreadable all the same. */
    public static function unreadable(ReceivedMessage $message, Throwable $reason): PermanentFailure
    {
        return new PermanentFailure(
            sprintf('Unreadable event at %s[%d]@%d: %s', $message->topic, $message->partition, $message->offset, $reason->getMessage()),
            previous: $reason,
        );
    }
}
