<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Kafka;

use Commerce\Ordering\Application\OrderSummary;
use Commerce\Ordering\Application\Port\Driving\ForProjectingOrderViews;
use Commerce\Ordering\Application\ProjectionOutcome;
use Commerce\Ordering\Application\StatusMove;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Ordering\Domain\Order\Quantity;
use Commerce\Ordering\Domain\Product\Sku;
use Commerce\Ordering\Domain\Store\StoreSlug;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\IncomingEvent;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Messaging\EventFields;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;
use ValueError;

/**
 * Reads commerce.orders.v2 for the consumer group commerce.order-projector (UC-ORD-08):
 * order.placed opens the view of the order in the customer's list, with the store the order
 * was placed in, and paid, cancelled, shipped, delivered and returned move it on. The events
 * of one order share a partition, so they come in the order they happened; the version in
 * the view covers a replay too.
 */
final readonly class OrderViewProjector implements MessageHandler
{
    public function __construct(private ForProjectingOrderViews $views, private LoggerInterface $logger) {}

    public function handle(ReceivedMessage $message): void
    {
        $event = IncomingEvent::read($message);
        $status = self::statusAfter($event->type);
        if ($status === null) {
            return;
        }
        Context::add('correlation_id', $event->correlationId);
        Context::add('causation_id', $event->id);

        $outcome = $status === OrderStatus::PendingPayment
            ? $this->views->open(self::summaryOf($event, $message))
            : $this->views->move(self::moveOf($status, $event, $message));
        if ($outcome === ProjectionOutcome::Missing) {
            // Nothing to fix by trying again: the list will not have this order, and the order itself is fine.
            $this->logger->warning('Order {orderId} has no view to move to {status}: its order.placed never reached the list', ['orderId' => $event->subject, 'status' => $status->value]);

            return;
        }
        $this->logger->debug('Order view of {orderId}: {status} {outcome}', ['orderId' => $event->subject, 'status' => $status->value, 'outcome' => $outcome->value]);
    }

    /** The status each event of the topic takes its order to; null for an event the list has no use for. */
    private static function statusAfter(string $type): ?OrderStatus
    {
        return match ($type) {
            'tucano.commerce.order.placed' => OrderStatus::PendingPayment,
            'tucano.commerce.order.paid' => OrderStatus::Paid,
            'tucano.commerce.order.cancelled' => OrderStatus::Cancelled,
            'tucano.commerce.order.shipped' => OrderStatus::Shipped,
            'tucano.commerce.order.delivered' => OrderStatus::Delivered,
            'tucano.commerce.order.returned' => OrderStatus::Returned,
            default => null,
        };
    }

    /** The placement time is the time of the event. An order.placed from before the stores has no store (ADR 0031). */
    private static function summaryOf(CloudEvent $event, ReceivedMessage $message): OrderSummary
    {
        try {
            $data = new EventFields($event->data);
            $store = $data->optionalText('store');

            return OrderSummary::placed(
                OrderId::fromString($data->uuid('orderId')),
                OrderNumber::fromString($data->text('orderNumber')),
                $store === null ? null : StoreSlug::of($store),
                CustomerId::fromString($data->uuid('customerId')),
                OrderLines::of(...array_map(self::lineOf(...), $data->objects('lines'))),
                $event->time,
            );
        } catch (InvalidArgumentException|DomainError|ValueError $invalid) {
            throw IncomingEvent::unreadable($message, $invalid);
        }
    }

    /** An order.placed from before the event carried names has none: the list shows the SKU instead. */
    private static function lineOf(EventFields $line): OrderLine
    {
        $sku = $line->text('sku');
        $name = $line->optionalText('name');
        $price = $line->object('unitPrice');

        return new OrderLine(
            Sku::of($sku),
            $name === null || $name === '' ? $sku : $name,
            Quantity::of($line->integer('quantity')),
            Money::of($price->integer('amount'), Currency::fromCode($price->text('currency'))),
        );
    }

    private static function moveOf(OrderStatus $status, CloudEvent $event, ReceivedMessage $message): StatusMove
    {
        try {
            $data = new EventFields($event->data);
            $orderId = OrderId::fromString($data->uuid('orderId'));
            if ($status !== OrderStatus::Cancelled) {
                return StatusMove::to($status, $orderId, $event->time);
            }

            return StatusMove::cancelled($orderId, CancellationReason::from($data->text('reason')), OrderStatus::from($data->text('previousStatus')), $event->time);
        } catch (InvalidArgumentException|DomainError|ValueError $invalid) {
            throw IncomingEvent::unreadable($message, $invalid);
        }
    }
}
