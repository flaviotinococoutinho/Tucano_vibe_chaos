<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderTransitionNotAllowed;
use Commerce\Ordering\Domain\Event\OrderCancelled;
use Commerce\Ordering\Domain\Event\OrderDelivered;
use Commerce\Ordering\Domain\Event\OrderPaid;
use Commerce\Ordering\Domain\Event\OrderPlaced;
use Commerce\Ordering\Domain\Event\OrderReturned;
use Commerce\Ordering\Domain\Event\OrderShipped;
use Commerce\Ordering\Domain\Store\StoreSlug;
use DateTimeImmutable;
use Tucano\SharedKernel\Address\Address;
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
        /** Null only for the orders placed before the stores (ADR 0031): every new order has one. */
        private readonly ?StoreSlug $store,
        private readonly Customer $customer,
        private readonly Address $address,
        private readonly OrderLines $lines,
        private readonly FulfillmentCenterCode $fulfillmentCenter,
        private readonly DateTimeImmutable $placedAt,
        private readonly DateTimeImmutable $reservationExpiresAt,
        public private(set) OrderStatus $status,
        public private(set) int $version,
        private ?TrackingCode $trackingCode = null,
        private ?CancellationReason $cancellationReason = null,
    ) {}

    public static function place(
        OrderId $id,
        OrderNumber $number,
        StoreSlug $store,
        Customer $customer,
        Address $address,
        OrderLines $lines,
        FulfillmentCenterCode $fulfillmentCenter,
        DateTimeImmutable $placedAt,
        DateTimeImmutable $reservationExpiresAt,
    ): self {
        $order = new self($id, $number, $store, $customer, $address, $lines, $fulfillmentCenter, $placedAt, $reservationExpiresAt, OrderStatus::PendingPayment, 1);
        $order->transitions[] = StatusTransition::initial(OrderStatus::PendingPayment, $placedAt);
        $order->recordThat(new OrderPlaced($id, $number, $store, $customer->id, $lines, $fulfillmentCenter, $reservationExpiresAt, $placedAt));

        return $order;
    }

    public static function fromSnapshot(OrderSnapshot $snapshot): self
    {
        return new self(
            $snapshot->id,
            $snapshot->number,
            $snapshot->store,
            $snapshot->customer,
            $snapshot->address,
            $snapshot->lines,
            $snapshot->fulfillmentCenter,
            $snapshot->placedAt,
            $snapshot->reservationExpiresAt,
            $snapshot->status,
            $snapshot->version,
            $snapshot->trackingCode,
            $snapshot->cancellationReason,
        );
    }

    public function toSnapshot(): OrderSnapshot
    {
        return new OrderSnapshot(
            $this->id,
            $this->number,
            $this->store,
            $this->customer,
            $this->address,
            $this->lines,
            $this->fulfillmentCenter,
            $this->status,
            $this->placedAt,
            $this->reservationExpiresAt,
            $this->version,
            $this->trackingCode,
            $this->cancellationReason,
        );
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    /** A customer sees only the orders it placed (ADR 0030). */
    public function isPlacedBy(CustomerId $customer): bool
    {
        return $this->customer->is($customer);
    }

    /** A store shows only its own orders (ADR 0031); an order from before the stores is in none. */
    public function isPlacedIn(StoreSlug $store): bool
    {
        return $this->store !== null && $this->store->equals($store);
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
        $this->recordThat(new OrderPaid($this->id, $this->number, $this->store, $this->customer, $this->address, $this->lines, $this->fulfillmentCenter, $paidAt));
    }

    public function cancel(CancellationReason $reason, DateTimeImmutable $cancelledAt): void
    {
        $previous = $this->status;
        $this->moveTo(OrderStatus::Cancelled, $cancelledAt, $reason->value);
        $this->cancellationReason = $reason;
        $this->recordThat(new OrderCancelled($this->id, $this->number, $this->store, $reason, $previous, $cancelledAt));
    }

    /** The carrier picked the parcels up: the order learns the code of its shipment and keeps it from then on. */
    public function markAsShipped(TrackingCode $trackingCode, DateTimeImmutable $shippedAt): void
    {
        $this->moveTo(OrderStatus::Shipped, $shippedAt);
        $this->trackingCode = $trackingCode;
        $this->recordThat(new OrderShipped($this->id, $this->number, $this->store, $shippedAt));
    }

    public function markAsDelivered(DateTimeImmutable $deliveredAt): void
    {
        $this->moveTo(OrderStatus::Delivered, $deliveredAt);
        $this->recordThat(new OrderDelivered($this->id, $this->number, $this->store, $deliveredAt));
    }

    public function markAsReturned(DateTimeImmutable $returnedAt): void
    {
        $this->moveTo(OrderStatus::Returned, $returnedAt);
        $this->recordThat(new OrderReturned($this->id, $this->number, $this->store, $returnedAt));
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
        $this->transitions[] = StatusTransition::between($this->status, $target, $at, $reason);
        $this->status = $target;
        $this->version++;
    }
}
