<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Event;

use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Commerce\Ordering\Domain\Store\StoreSlug;
use DateTimeImmutable;
use Tucano\SharedKernel\Address\Address;

/**
 * Carries everything Logistics needs to create the shipment (event-carried
 * state transfer), so Logistics never has to call Commerce back.
 */
final readonly class OrderPaid extends OrderEvent
{
    public function __construct(
        OrderId $orderId,
        OrderNumber $orderNumber,
        ?StoreSlug $store,
        private Customer $customer,
        private Address $address,
        private OrderLines $lines,
        private FulfillmentCenterCode $center,
        DateTimeImmutable $paidAt,
    ) {
        parent::__construct($orderId, $orderNumber, $store, $paidAt);
    }

    protected function fact(): string
    {
        return 'paid';
    }

    protected function details(): array
    {
        return [
            'customer' => [
                'id' => $this->customer->id->toString(),
                'name' => $this->customer->name->reveal(),
                'email' => $this->customer->email->reveal(),
            ],
            'shippingAddress' => $this->address->toArray(),
            'fulfillmentCenter' => (string) $this->center,
            'lines' => array_map(static fn(OrderLine $line): array => [
                'sku' => (string) $line->sku,
                'name' => $line->productName,
                'quantity' => $line->quantity->value,
            ], iterator_to_array($this->lines, false)),
            'total' => $this->lines->total()->toArray(),
        ];
    }
}
