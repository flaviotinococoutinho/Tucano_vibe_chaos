<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Kafka;

use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Logistics\Shipping\Application\Port\Driving\ForBookingPickups;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\IncomingEvent;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;
use Tucano\SharedKernel\Messaging\EventFields;

/**
 * Reads logistics.shipments.v1 for the consumer group logistics.pickup-bookings:
 * a shipment with its label ready asks its carrier to come. A carrier that does
 * not answer is retried (the partition waits for it); a refusal goes to the dead
 * letter topic, with a warning, because a person has to look at it.
 */
final readonly class PickupBookingHandler implements MessageHandler
{
    private const string READY_FOR_PICKUP = 'tucano.logistics.shipment.ready_for_pickup';

    public function __construct(private ForBookingPickups $pickups, private LoggerInterface $logger) {}

    public function handle(ReceivedMessage $message): void
    {
        $event = IncomingEvent::read($message);
        if ($event->type !== self::READY_FOR_PICKUP) {
            return;
        }
        try {
            $shipment = ShipmentId::fromString(new EventFields($event->data)->uuid('shipmentId'));
        } catch (InvalidArgumentException $invalid) {
            throw IncomingEvent::unreadable($message, $invalid);
        }
        Context::add('correlation_id', $event->correlationId);
        Context::add('causation_id', $event->id);

        try {
            $outcome = $this->pickups->book($shipment);
        } catch (DomainError $refusal) {
            if ($refusal->category() === ErrorCategory::Unavailable) {
                throw $refusal;
            }
            $this->logger->warning('Pickup of shipment {shipmentId} was refused and needs a person: {reason}', ['shipmentId' => $shipment->toString(), 'reason' => $refusal->getMessage()]);

            throw new PermanentFailure(sprintf('Pickup of shipment %s refused: %s', $shipment, $refusal->getMessage()), previous: $refusal);
        }
        $this->logger->info('Pickup of shipment {shipmentId}: {outcome}', ['shipmentId' => $shipment->toString(), 'outcome' => $outcome->value]);
    }
}
