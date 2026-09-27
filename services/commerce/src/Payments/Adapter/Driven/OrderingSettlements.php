<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driven;

use Commerce\Ordering\Application\PaymentSettlement;
use Commerce\Ordering\Application\Port\Driving\ForSettlingOrderPayments;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Payments\Application\Port\Driven\ForSettlingOrders;
use DateTimeImmutable;

final readonly class OrderingSettlements implements ForSettlingOrders
{
    public function __construct(private ForSettlingOrderPayments $orders) {}

    public function markPaid(string $orderId, DateTimeImmutable $paidAt): bool
    {
        return $this->orders->markPaid(OrderId::fromString($orderId), $paidAt) === PaymentSettlement::Paid;
    }

    public function cancelDeclined(string $orderId, DateTimeImmutable $at): void
    {
        $this->orders->cancelDeclined(OrderId::fromString($orderId), $at);
    }
}
