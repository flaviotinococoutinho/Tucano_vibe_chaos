<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Kafka;

use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Logistics\Shipping\Application\Port\Driving\ForRequestingLabels;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\IncomingEvent;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Messaging\EventFields;

/**
 * Reads logistics.shipments.v2 for the consumer group logistics.label-requests:
 * every created shipment asks for its label. Kafka is the log and SQS the work
 * queue, and this handler is the bridge between the two.
 */
final readonly class LabelRequestHandler implements MessageHandler
{
    private const string CREATED = 'tucano.logistics.shipment.created';

    public function __construct(private ForRequestingLabels $labels, private LoggerInterface $logger) {}

    public function handle(ReceivedMessage $message): void
    {
        $event = IncomingEvent::read($message);
        if ($event->type !== self::CREATED) {
            return;
        }
        try {
            $shipment = ShipmentId::fromString(new EventFields($event->data)->uuid('shipmentId'));
        } catch (InvalidArgumentException $invalid) {
            throw IncomingEvent::unreadable($message, $invalid);
        }
        Context::add('correlation_id', $event->correlationId);
        Context::add('causation_id', $event->id);

        $this->labels->request($shipment);
        $this->logger->info('Label of shipment {shipmentId} requested', ['shipmentId' => $shipment->toString()]);
    }
}
