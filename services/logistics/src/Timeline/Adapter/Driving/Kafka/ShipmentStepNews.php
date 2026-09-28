<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driving\Kafka;

use InvalidArgumentException;
use Logistics\Timeline\Application\TimelineNews;
use Logistics\Timeline\Domain\JourneyStatus;
use Logistics\Timeline\Domain\Place;
use Logistics\Timeline\Domain\TimelineStep;
use Tucano\Messaging\Kafka\IncomingEvent;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Messaging\EventFields;
use ValueError;

/**
 * Reads an event of logistics.shipments.v2 as a step of the journey, the same way for the
 * internal timeline and for the public page. The events of one shipment share a partition,
 * so the steps come in the order they happened.
 */
final class ShipmentStepNews
{
    private const string PREFIX = 'tucano.logistics.shipment.';

    private function __construct() {}

    /** Null for an event that is not a step of the journey. */
    public static function of(ReceivedMessage $message): ?TimelineNews
    {
        $event = IncomingEvent::read($message);
        $status = str_starts_with($event->type, self::PREFIX) ? JourneyStatus::tryFrom(substr($event->type, strlen(self::PREFIX))) : null;

        return $status === null ? null : self::newsOf($event, $status, $message);
    }

    private static function newsOf(CloudEvent $event, JourneyStatus $status, ReceivedMessage $message): TimelineNews
    {
        try {
            $data = new EventFields($event->data);
            $attempt = $data->optionalNumber('attempt');
            $step = TimelineStep::of($status, $event->time, $data->optionalText('hub'), $attempt === null ? null : (int) $attempt, $data->optionalText('reason'));
            $created = $status === JourneyStatus::Created;

            return TimelineNews::of(
                $data->uuid('shipmentId'),
                $data->uuid('orderId'),
                $data->text('trackingCode'),
                $event->id,
                $step,
                $created ? $data->text('carrier') : null,
                $created ? self::destinationOf($data->object('destination')) : null,
            );
        } catch (InvalidArgumentException|ValueError $invalid) {
            throw IncomingEvent::unreadable($message, $invalid);
        }
    }

    /** The divisions of shipment.created stop at the municipality: [state, municipality]. */
    private static function destinationOf(EventFields $destination): Place
    {
        [$state, $municipality] = $destination->objects('divisions') + [null, null];
        if ($state === null || $municipality === null) {
            throw new InvalidArgumentException('The destination has no state and municipality.');
        }

        return Place::of($municipality->text('name'), $state->text('code'));
    }
}
