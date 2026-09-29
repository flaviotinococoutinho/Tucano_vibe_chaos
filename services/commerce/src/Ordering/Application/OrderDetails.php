<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderSnapshot;
use Commerce\Ordering\Domain\Order\StatusTransition;
use DateTimeImmutable;
use DateTimeZone;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/**
 * What the outside world sees of an order. It is also the result stored for an
 * idempotency key, so a repeated request gets exactly the same answer; the reads
 * add the history of the order to it (withHistory), the answer to a POST does not.
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
            // Null only for an order placed before the stores (ADR 0031).
            'store' => $order->store === null ? null : (string) $order->store,
            'status' => $order->status->value,
            // Masked on purpose (LGPD, minimization): there is no login, so whoever has the
            // id of an order sees the order, not who bought it.
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

    /**
     * The same view with the story of the order: every status it went through, oldest first,
     * when it got there, and why, for a cancellation.
     *
     * @param list<StatusTransition> $transitions
     */
    public function withHistory(array $transitions): self
    {
        $utc = new DateTimeZone('UTC');

        return new self([...$this->fields, 'history' => array_map(static fn(StatusTransition $step): array => [
            'status' => $step->to->value,
            'at' => $step->at->setTimezone($utc)->format(DATE_RFC3339_EXTENDED),
            'reason' => $step->reason,
        ], $transitions)]);
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
