<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Event;

use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Ordering\Domain\Store\StoreSlug;
use DateTimeImmutable;

final readonly class OrderCancelled extends OrderEvent
{
    public function __construct(
        OrderId $orderId,
        OrderNumber $orderNumber,
        ?StoreSlug $store,
        private CancellationReason $reason,
        private OrderStatus $previousStatus,
        DateTimeImmutable $cancelledAt,
    ) {
        parent::__construct($orderId, $orderNumber, $store, $cancelledAt);
    }

    protected function fact(): string
    {
        return 'cancelled';
    }

    protected function details(): array
    {
        // A paid order being cancelled means money to give back and a shipment to stop.
        return ['reason' => $this->reason->value, 'previousStatus' => $this->previousStatus->value];
    }
}
