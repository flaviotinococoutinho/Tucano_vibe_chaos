<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Event;

use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\OrderNumber;
use DateTimeImmutable;

final readonly class OrderPlaced extends OrderEvent
{
    public function __construct(
        OrderId $orderId,
        OrderNumber $orderNumber,
        private CustomerId $customerId,
        private OrderLines $lines,
        private FulfillmentCenterCode $center,
        private DateTimeImmutable $reservationExpiresAt,
        DateTimeImmutable $placedAt,
    ) {
        parent::__construct($orderId, $orderNumber, $placedAt);
    }

    protected function fact(): string
    {
        return 'placed';
    }

    protected function details(): array
    {
        return [
            'customerId' => $this->customerId->toString(),
            'fulfillmentCenter' => (string) $this->center,
            'total' => $this->lines->total()->toArray(),
            'lines' => array_map(static fn(OrderLine $line): array => [
                'sku' => (string) $line->sku,
                'quantity' => $line->quantity->value,
                'unitPrice' => $line->unitPrice->toArray(),
            ], iterator_to_array($this->lines, false)),
            'reservationExpiresAt' => $this->reservationExpiresAt->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
