<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Ordering\Domain\Error\OrderTransitionNotAllowed;
use Commerce\Ordering\Domain\Event\OrderCancelled;
use Commerce\Ordering\Domain\Event\OrderDelivered;
use Commerce\Ordering\Domain\Event\OrderPaid;
use Commerce\Ordering\Domain\Event\OrderPlaced;
use Commerce\Ordering\Domain\Event\OrderReturned;
use Commerce\Ordering\Domain\Event\OrderShipped;
use DateTimeImmutable;
use Tucano\SharedKernel\Domain\AggregateRoot;
use Tucano\SharedKernel\Money\Money;

/**
 * Aggregate root of Ordering. Every change goes through the state machine in
 * OrderStatus and records a domain event and a status transition; the
 * application layer stores the events in the outbox and the transitions in the
 * history, together with the new state.
 */
final class Order extends AggregateRoot
{
    /** @var list<StatusTransition> */
    private array $transitions = [];

    private function __construct(
        private readonly OrderId $id,
        private readonly OrderNumber $number,
        private readonly Customer $customer,
        private readonly ShippingAddress $address,
        private readonly OrderLines $lines,
        private readonly FulfillmentCenterCode $fulfillmentCenter,
        private readonly DateTimeImmutable $placedAt,
        private readonly DateTimeImmutable $reservationExpiresAt,
        public private(set) OrderStatus $status,
        public private(set) int $version,
    ) {}

    public static function place(
        OrderId $id,
        OrderNumber $number,
        Customer $customer,
        ShippingAddress $address,
        OrderLines $lines,
        FulfillmentCenterCode $fulfillmentCenter,
        DateTimeImmutable $placedAt,
        DateTimeImmutable $reservationExpiresAt,
    ): self {
        $order = new self($id, $number, $customer, $address, $lines, $fulfillmentCenter, $placedAt, $reservationExpiresAt, OrderStatus::PendingPayment, 1);
        $order->transitions[] = new StatusTransition(null, OrderStatus::PendingPayment, $placedAt);
        $order->recordThat(new OrderPlaced($id, $number, $customer->id, $lines, $fulfillmentCenter, $reservationExpiresAt, $placedAt));

        return $order;
    }

    public static function fromSnapshot(OrderSnapshot $snapshot): self
    {
        return new self(
            $snapshot->id,
            $snapshot->number,
            $snapshot->customer,
            $snapshot->address,
            $snapshot->lines,
            $snapshot->fulfillmentCenter,
            $snapshot->placedAt,
            $snapshot->reservationExpiresAt,
            $snapshot->status,
            $snapshot->version,
        );
    }

    public function toSnapshot(): OrderSnapshot
    {
        return new OrderSnapshot(
            $this->id,
            $this->number,
            $this->customer,
            $this->address,
            $this->lines,
            $this->fulfillmentCenter,
            $this->status,
            $this->placedAt,
            $this->reservationExpiresAt,
            $this->version,
        );
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function amountDue(): Money
    {
        return $this->lines->total();
    }

    public function reservationExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->status === OrderStatus::PendingPayment && $now >= $this->reservationExpiresAt;
    }

    public function markAsPaid(DateTimeImmutable $paidAt): void
    {
        $this->moveTo(OrderStatus::Paid, $paidAt);
        $this->recordThat(new OrderPaid($this->id, $this->number, $this->customer, $this->address, $this->lines, $this->fulfillmentCenter, $paidAt));
    }

    public function cancel(CancellationReason $reason, DateTimeImmutable $cancelledAt): void
    {
        $previous = $this->status;
        $this->moveTo(OrderStatus::Cancelled, $cancelledAt, $reason->value);
        $this->recordThat(new OrderCancelled($this->id, $this->number, $reason, $previous, $cancelledAt));
    }

    public function markAsShipped(DateTimeImmutable $shippedAt): void
    {
        $this->moveTo(OrderStatus::Shipped, $shippedAt);
        $this->recordThat(new OrderShipped($this->id, $this->number, $shippedAt));
    }

    public function markAsDelivered(DateTimeImmutable $deliveredAt): void
    {
        $this->moveTo(OrderStatus::Delivered, $deliveredAt);
        $this->recordThat(new OrderDelivered($this->id, $this->number, $deliveredAt));
    }

    public function markAsReturned(DateTimeImmutable $returnedAt): void
    {
        $this->moveTo(OrderStatus::Returned, $returnedAt);
        $this->recordThat(new OrderReturned($this->id, $this->number, $returnedAt));
    }

    /**
     * The transitions not stored yet, oldest first. Like events, they are handed
     * over once: the repository writes them to the history when it saves the order.
     *
     * @return list<StatusTransition>
     */
    public function releaseTransitions(): array
    {
        $transitions = $this->transitions;
        $this->transitions = [];

        return $transitions;
    }

    private function moveTo(OrderStatus $target, DateTimeImmutable $at, ?string $reason = null): void
    {
        if (!$this->status->canMoveTo($target)) {
            throw OrderTransitionNotAllowed::from($this->status, $target);
        }
        $this->transitions[] = new StatusTransition($this->status, $target, $at, $reason);
        $this->status = $target;
        $this->version++;
    }
}
