<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderSnapshot;
use DateTimeImmutable;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/**
 * What the outside world sees of an order. It is also the result stored for an
 * idempotency key, so a repeated request gets exactly the same answer.
 */
final readonly class OrderDetails
{
    /** @param array<string, mixed> $fields */
    private function __construct(private array $fields) {}

    public static function of(OrderSnapshot $order): self
    {
        return new self([
            'orderId' => $order->id->toString(),
            'orderNumber' => (string) $order->number,
            'status' => $order->status->value,
            'customer' => [
                'id' => $order->customer->id->toString(),
                'name' => (string) $order->customer->name,
                'email' => (string) $order->customer->email,
            ],
            'shippingAddress' => $order->address->toArray(),
            'fulfillmentCenter' => (string) $order->fulfillmentCenter,
            // Null until the carrier picks the order up; from then on, the way to the tracking page.
            'trackingCode' => $order->trackingCode === null ? null : (string) $order->trackingCode,
            // Null unless the order was cancelled: payment_declined, reservation_expired or customer_request.
            'cancellationReason' => $order->cancellationReason?->value,
            'lines' => array_map(static fn(OrderLine $line): array => [
                'sku' => (string) $line->sku,
                'name' => $line->productName,
                'quantity' => $line->quantity->value,
                'unitPrice' => $line->unitPrice->toArray(),
                'subtotal' => $line->subtotal()->toArray(),
            ], iterator_to_array($order->lines, false)),
            'total' => $order->lines->total()->toArray(),
            'placedAt' => $order->placedAt->format(DATE_RFC3339_EXTENDED),
            'reservationExpiresAt' => $order->reservationExpiresAt->format(DATE_RFC3339_EXTENDED),
        ]);
    }

    /** @param array<string, mixed> $fields */
    public static function fromStored(array $fields): self
    {
        return new self($fields);
    }

    public function orderId(): string
    {
        return (string) $this->fields['orderId'];
    }

    public function status(): string
    {
        return (string) $this->fields['status'];
    }

    public function total(): Money
    {
        /** @var array{amount: int, currency: string} $total */
        $total = $this->fields['total'];

        return Money::of($total['amount'], Currency::fromCode($total['currency']));
    }

    public function reservationExpiresAt(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->fields['reservationExpiresAt']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->fields;
    }
}
