<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\UseCase;

use Commerce\Ordering\Application\PaymentSettlement;
use Commerce\Ordering\Application\Port\Driven\ForReservingStock;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Application\Port\Driving\ForSettlingOrderPayments;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Shared\Application\Port\Driven\ForPublishingEvents;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use DateTimeImmutable;
use LogicException;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * The order row stays locked while its payment settles, so the expiry job and a
 * webhook can never both decide the fate of the same order.
 */
#[UseCase('UC-ORD-07')]
final readonly class SettleOrderPayment implements ForSettlingOrderPayments
{
    public function __construct(
        private ForRunningTransactions $transactions,
        private ForStoringOrders $orders,
        private ForReservingStock $stock,
        private ForPublishingEvents $events,
    ) {}

    public function markPaid(OrderId $order, DateTimeImmutable $paidAt): PaymentSettlement
    {
        return $this->transactions->run(function () use ($order, $paidAt): PaymentSettlement {
            $locked = $this->orders->lock($order);

            return match ($locked->status) {
                OrderStatus::PendingPayment => $this->pay($locked, $paidAt),
                OrderStatus::Cancelled => PaymentSettlement::TooLate,
                // One pending payment per order and one success per order (both unique
                // indexes) make this impossible; if it happens, it must be loud.
                OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Returned => throw new LogicException(
                    sprintf('Order %s is already %s; a second payment cannot settle it.', $order->toString(), $locked->status->value),
                ),
            };
        });
    }

    public function cancelDeclined(OrderId $order, DateTimeImmutable $at): void
    {
        $this->transactions->run(function () use ($order, $at): void {
            $locked = $this->orders->lock($order);
            if ($locked->status !== OrderStatus::PendingPayment) {
                // Already cancelled by the expiry job: nothing left to undo.
                return;
            }
            $locked->cancel(CancellationReason::PaymentDeclined, $at);
            $this->stock->release($order);
            $this->save($locked);
        });
    }

    private function pay(Order $order, DateTimeImmutable $paidAt): PaymentSettlement
    {
        $order->markAsPaid($paidAt);
        $this->stock->commit($order->id());
        $this->save($order);

        return PaymentSettlement::Paid;
    }

    private function save(Order $order): void
    {
        $this->orders->save($order);
        $this->events->publish(...$order->releaseEvents());
    }
}
