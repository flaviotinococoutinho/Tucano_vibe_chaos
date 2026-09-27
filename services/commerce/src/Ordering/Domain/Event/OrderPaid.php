<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Event;

use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\OrderNumber;
use DateTimeImmutable;

/**
 * Carries everything Logistics needs to create the shipment (event-carried
 * state transfer), so Logistics never has to call Commerce back.
 */
final readonly class OrderPaid extends OrderEvent
{
    public function __construct(
        OrderId $orderId,
        OrderNumber $orderNumber,
        private Customer $customer,
        private ShippingAddress $address,
        private OrderLines $lines,
        private FulfillmentCenterCode $center,
        DateTimeImmutable $paidAt,
    ) {
        parent::__construct($orderId, $orderNumber, $paidAt);
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
                'name' => (string) $this->customer->name,
                'email' => (string) $this->customer->email,
            ],
            'shippingAddress' => [
                'street' => $this->address->street,
                'number' => $this->address->number,
                'complement' => $this->address->complement,
                'district' => $this->address->district,
                'city' => $this->address->city,
                'state' => $this->address->state->value,
                'postalCode' => (string) $this->address->postalCode,
                'latitude' => $this->address->coordinates?->latitude,
                'longitude' => $this->address->coordinates?->longitude,
            ],
            'fulfillmentCenter' => (string) $this->center,
            'lines' => array_map(static fn(OrderLine $line): array => [
                'sku' => (string) $line->sku,
                'name' => $line->productName,
                'quantity' => $line->quantity->value,
            ], iterator_to_array($this->lines, false)),
            'total' => $this->lines->total()->jsonSerialize(),
        ];
    }
}
