<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driven;

use Commerce\Ordering\Application\Port\Driven\ForRefundingOrders;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Payments\Application\Port\Driving\ForRequestingRefunds;
use Commerce\Payments\Application\RefundRequest;
use Psr\Log\LoggerInterface;

/**
 * Ordering asks Payments through its driving port, with plain values: if
 * Payments becomes a service of its own, only this adapter changes.
 */
final readonly class PaymentsRefunds implements ForRefundingOrders
{
    public function __construct(private ForRequestingRefunds $payments, private LoggerInterface $logger) {}

    public function refund(OrderId $order): void
    {
        if ($this->payments->refundOrder($order->toString()) === RefundRequest::NothingToRefund) {
            // Returned without a captured payment: it was never charged, or its refund is already on the way.
            $this->logger->warning('Order {orderId} came back with no captured payment to refund', ['orderId' => $order->toString()]);
        }
    }
}
