<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Kafka;

use Closure;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Logistics\Shipping\Application\CancellationOutcome;
use Logistics\Shipping\Application\CancelledOrder;
use Logistics\Shipping\Application\CreatedShipment;
use Logistics\Shipping\Application\PaidOrder;
use Logistics\Shipping\Application\Port\Driving\ForCancellingShipments;
use Logistics\Shipping\Application\Port\Driving\ForCreatingShipments;
use Logistics\Shipping\Application\ShipmentSkipped;
use Psr\Log\LoggerInterface;
use Throwable;
use Tucano\Messaging\Kafka\IncomingEvent;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;
use Tucano\SharedKernel\Messaging\CloudEvent;
use ValueError;

/**
 * Reads commerce.orders.v2 for the consumer group logistics.order-intake: a
 * paid order becomes a shipment and a cancelled one stops it; the other order
 * events are not for Logistics. What a retry cannot fix goes to the dead
 * letter topic at once: an unreadable event, or a refusal of the domain, which
 * also logs a warning because a person has to look at it. An unavailable
 * dependency, like a product the catalog copy has not seen yet, is retried.
 */
final readonly class OrderEventHandler implements MessageHandler
{
    private const string PAID = 'tucano.commerce.order.paid';

    private const string CANCELLED = 'tucano.commerce.order.cancelled';

    public function __construct(
        private ForCreatingShipments $creations,
        private ForCancellingShipments $cancellations,
        private LoggerInterface $logger,
    ) {}

    public function handle(ReceivedMessage $message): void
    {
        $event = IncomingEvent::read($message);
        if ($event->type !== self::PAID && $event->type !== self::CANCELLED) {
            return;
        }
        // Every line logged and every event published from here on belongs to the flow that paid or cancelled the order.
        Context::add('correlation_id', $event->correlationId);
        Context::add('causation_id', $event->id);

        if ($event->type === self::PAID) {
            $this->create($event, $message);

            return;
        }
        $this->cancel($event, $message);
    }

    private function create(CloudEvent $event, ReceivedMessage $message): void
    {
        $order = self::translate($message, static fn(): PaidOrder => OrderEventTranslator::paidOrder($event));
        $created = $this->attempt($event, fn(): CreatedShipment|ShipmentSkipped => $this->creations->create($order));
        if ($created instanceof ShipmentSkipped) {
            $this->logger->info(match ($created) {
                ShipmentSkipped::Repeated => 'Order event {eventId} was already handled',
                ShipmentSkipped::OrderCancelled => 'Order {orderId} was cancelled before its payment got here, so it does not ship',
            }, ['eventId' => $event->id, 'orderId' => $order->orderId->toString()]);

            return;
        }
        $this->logger->info('Shipment {trackingCode} created for order {orderId}, carried by {carrier}', [
            'trackingCode' => (string) $created->shipment->trackingCode,
            'orderId' => $created->shipment->orderId->toString(),
            'carrier' => (string) $created->carrier,
            'store' => $created->shipment->store === null ? null : (string) $created->shipment->store,
        ]);
    }

    private function cancel(CloudEvent $event, ReceivedMessage $message): void
    {
        $order = self::translate($message, static fn(): ?CancelledOrder => OrderEventTranslator::cancelledPaidOrder($event));
        if ($order === null) {
            $this->logger->debug('Order {orderId} was cancelled before payment and has no shipment', ['orderId' => $event->subject]);

            return;
        }
        $outcome = $this->attempt($event, fn(): CancellationOutcome => $this->cancellations->cancel($order));
        $this->logger->info(match ($outcome) {
            CancellationOutcome::Cancelled => 'Shipment of order {orderId} cancelled',
            CancellationOutcome::NoShipment => 'Order {orderId} has no shipment yet; its payment will not ship it',
            CancellationOutcome::Repeated => 'Order event {eventId} was already handled',
        }, ['orderId' => $order->orderId->toString(), 'eventId' => $event->id]);
    }

    /**
     * @template T
     *
     * @param Closure(): T $translation
     *
     * @return T
     */
    private static function translate(ReceivedMessage $message, Closure $translation): mixed
    {
        try {
            return $translation();
        } catch (InvalidArgumentException|DomainError|ValueError $invalid) {
            throw IncomingEvent::unreadable($message, $invalid);
        }
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function attempt(CloudEvent $event, Closure $work): mixed
    {
        try {
            return $work();
        } catch (DomainError $refusal) {
            throw $this->afterRefusal($event, $refusal);
        }
    }

    /** An unavailable dependency may answer on the next try; any other refusal would be the same again. */
    private function afterRefusal(CloudEvent $event, DomainError $refusal): Throwable
    {
        $context = ['type' => $event->type, 'orderId' => $event->subject, 'reason' => $refusal->getMessage()];
        if ($refusal->category() === ErrorCategory::Unavailable) {
            $this->logger->info('{type} of order {orderId} will be retried: {reason}', $context);

            return $refusal;
        }
        $this->logger->warning('{type} of order {orderId} was refused and needs a person: {reason}', $context);

        return new PermanentFailure(sprintf('%s of order %s refused: %s', $event->type, $event->subject, $refusal->getMessage()), previous: $refusal);
    }
}
