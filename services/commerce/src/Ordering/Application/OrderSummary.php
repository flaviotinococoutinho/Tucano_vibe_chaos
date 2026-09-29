<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Ordering\Domain\Store\StoreSlug;
use DateTimeImmutable;
use DateTimeZone;
use Tucano\SharedKernel\Money\Money;

/**
 * An order as the customer's list shows it (UC-ORD-05), kept in the read model
 * order_views by the projection of commerce.orders.v2 (UC-ORD-08). It can lag the
 * order by the time the projection takes (BASE, ADR 0012), and it knows who bought
 * only by the customer id. The list of a store holds only the orders of that store
 * (ADR 0031); a view from before the stores has none and is in no list.
 */
final readonly class OrderSummary
{
    private function __construct(
        public OrderId $orderId,
        public OrderNumber $orderNumber,
        public ?StoreSlug $store,
        public CustomerId $customerId,
        public OrderStatus $status,
        public ?CancellationReason $cancellationReason,
        public OrderLines $lines,
        public DateTimeImmutable $placedAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** What order.placed opens in the list: an order waiting for payment since it was placed. */
    public static function placed(OrderId $orderId, OrderNumber $orderNumber, ?StoreSlug $store, CustomerId $customerId, OrderLines $lines, DateTimeImmutable $placedAt): self
    {
        return new self($orderId, $orderNumber, $store, $customerId, OrderStatus::PendingPayment, null, $lines, $placedAt, $placedAt);
    }

    /** An order as the read model keeps it, moved on by the events that came after order.placed. */
    public static function of(
        OrderId $orderId,
        OrderNumber $orderNumber,
        ?StoreSlug $store,
        CustomerId $customerId,
        OrderStatus $status,
        ?CancellationReason $cancellationReason,
        OrderLines $lines,
        DateTimeImmutable $placedAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($orderId, $orderNumber, $store, $customerId, $status, $cancellationReason, $lines, $placedAt, $updatedAt);
    }

    public function total(): Money
    {
        return $this->lines->total();
    }

    /** @return array{orderId: string, orderNumber: string, store: ?string, status: string, cancellationReason: ?string, total: array{amount: int, currency: string}, lines: list<array{sku: string, name: string, quantity: int}>, placedAt: string, updatedAt: string} */
    public function toArray(): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            'orderId' => $this->orderId->toString(),
            'orderNumber' => (string) $this->orderNumber,
            'store' => $this->store === null ? null : (string) $this->store,
            'status' => $this->status->value,
            'cancellationReason' => $this->cancellationReason?->value,
            'total' => $this->total()->toArray(),
            'lines' => array_map(static fn(OrderLine $line): array => [
                'sku' => (string) $line->sku,
                'name' => $line->productName,
                'quantity' => $line->quantity->value,
            ], iterator_to_array($this->lines, false)),
            'placedAt' => $this->placedAt->setTimezone($utc)->format(DATE_RFC3339_EXTENDED),
            'updatedAt' => $this->updatedAt->setTimezone($utc)->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
