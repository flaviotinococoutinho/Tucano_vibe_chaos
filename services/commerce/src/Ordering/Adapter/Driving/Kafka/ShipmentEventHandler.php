<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Kafka;

use Commerce\Ordering\Application\FollowOutcome;
use Commerce\Ordering\Application\Port\Driving\ForFollowingShipments;
use Commerce\Ordering\Application\ShipmentNews;
use Commerce\Ordering\Domain\Order\OrderId;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\IncomingEvent;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Messaging\EventFields;

/**
 * Reads logistics.shipments.v2 for the consumer group commerce.shipment-sync
 * (UC-ORD-04): a pickup ships the order, a delivery delivers it, a return
 * returns it. The other steps of the journey are for Logistics and for the
 * customer's tracking page, not for the order. The events of one shipment share
 * a partition, so they come in the order Logistics published them.
 */
final readonly class ShipmentEventHandler implements MessageHandler
{
    public function __construct(private ForFollowingShipments $orders, private LoggerInterface $logger) {}

    public function handle(ReceivedMessage $message): void
    {
        $event = IncomingEvent::read($message);
        $step = match ($event->type) {
            'tucano.logistics.shipment.picked_up' => $this->orders->recordShipped(...),
            'tucano.logistics.shipment.delivered' => $this->orders->recordDelivered(...),
            'tucano.logistics.shipment.returned' => $this->orders->recordReturned(...),
            default => null,
        };
        if ($step === null) {
            return;
        }
        Context::add('correlation_id', $event->correlationId);
        Context::add('causation_id', $event->id);

        $news = self::newsOf($event, $message);
        try {
            $outcome = $step($news);
        } catch (DomainError $refused) {
            if ($refused->category() === ErrorCategory::Unavailable) {
                throw $refused;
            }
            // The order cannot take this step (unknown order, or one that went another way): a person has to look.
            $this->logger->warning('Order {orderId} refused {type}: {reason}', ['orderId' => $news->orderId->toString(), 'type' => $event->type, 'reason' => $refused->getMessage()]);

            throw new PermanentFailure($refused->getMessage(), previous: $refused);
        }
        $this->logger->info('Order {orderId} follows its shipment: {type}, {outcome}', [
            'orderId' => $news->orderId->toString(),
            'type' => $event->type,
            'outcome' => $outcome === FollowOutcome::Applied ? 'applied' : 'already handled',
        ]);
    }

    private static function newsOf(CloudEvent $event, ReceivedMessage $message): ShipmentNews
    {
        try {
            return ShipmentNews::of($event->id, OrderId::fromString((new EventFields($event->data))->uuid('orderId')), $event->time);
        } catch (InvalidArgumentException|DomainError $invalid) {
            throw IncomingEvent::unreadable($message, $invalid);
        }
    }
}
