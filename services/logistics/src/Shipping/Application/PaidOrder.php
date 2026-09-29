<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\Recipient;
use Logistics\Shipping\Domain\Shipment\StoreSlug;
use Tucano\SharedKernel\Address\Address;

/**
 * What Logistics takes from an order.paid event: enough to ship without ever
 * calling Commerce. The event id is what the inbox remembers. An event from
 * before the stores has no store (ADR 0031).
 */
final readonly class PaidOrder
{
    /** @param list<OrderLine> $lines */
    public function __construct(
        public string $eventId,
        public OrderId $orderId,
        public ?StoreSlug $store,
        public Recipient $recipient,
        public Address $destination,
        public FulfillmentCenterCode $origin,
        public array $lines,
    ) {}
}
