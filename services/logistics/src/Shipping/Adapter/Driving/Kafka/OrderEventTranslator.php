<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Kafka;

use Logistics\Shipping\Application\CancelledOrder;
use Logistics\Shipping\Application\OrderLine;
use Logistics\Shipping\Application\PaidOrder;
use Logistics\Shipping\Domain\Parcel\Quantity;
use Logistics\Shipping\Domain\Product\Sku;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\Recipient;
use Logistics\Shipping\Domain\Shipment\StoreSlug;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Messaging\EventFields;

/**
 * Translates the published language of Commerce (contracts/events/commerce.order.*)
 * into the model of Shipping, reading only the fields Logistics uses. Data that
 * does not fit the model throws InvalidArgumentException, ValueError or a domain error.
 */
final readonly class OrderEventTranslator
{
    private const string UNPAID = 'pending_payment';

    private function __construct() {}

    /** An order.paid from before the stores has no store: its shipment looks for one in the products. */
    public static function paidOrder(CloudEvent $event): PaidOrder
    {
        $data = new EventFields($event->data);
        $customer = $data->object('customer');
        $store = $data->optionalText('store');

        return new PaidOrder(
            $event->id,
            OrderId::fromString($data->text('orderId')),
            $store === null ? null : StoreSlug::of($store),
            Recipient::of($customer->text('name'), $customer->text('email')),
            Address::fromArray($data->object('shippingAddress')->toArray()),
            FulfillmentCenterCode::of($data->text('fulfillmentCenter')),
            array_map(
                static fn(EventFields $line): OrderLine => new OrderLine(Sku::of($line->text('sku')), Quantity::of($line->integer('quantity'))),
                $data->objects('lines'),
            ),
        );
    }

    /** Null for an order cancelled while it waited for payment: it never had a shipment. */
    public static function cancelledPaidOrder(CloudEvent $event): ?CancelledOrder
    {
        $data = new EventFields($event->data);
        if ($data->text('previousStatus') === self::UNPAID) {
            return null;
        }

        return new CancelledOrder($event->id, OrderId::fromString($data->text('orderId')));
    }
}
