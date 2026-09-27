<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderSnapshot;

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
        $address = $order->address;

        return new self([
            'orderId' => $order->id->toString(),
            'orderNumber' => (string) $order->number,
            'status' => $order->status->value,
            'customer' => [
                'id' => $order->customer->id->toString(),
                'name' => (string) $order->customer->name,
                'email' => (string) $order->customer->email,
            ],
            'shippingAddress' => [
                'street' => $address->street,
                'number' => $address->number,
                'complement' => $address->complement,
                'district' => $address->district,
                'city' => $address->city,
                'state' => $address->state->value,
                'postalCode' => (string) $address->postalCode,
            ],
            'fulfillmentCenter' => (string) $order->fulfillmentCenter,
            'lines' => array_map(static fn(OrderLine $line): array => [
                'sku' => (string) $line->sku,
                'name' => $line->productName,
                'quantity' => $line->quantity->value,
                'unitPrice' => $line->unitPrice->jsonSerialize(),
                'subtotal' => $line->subtotal()->jsonSerialize(),
            ], iterator_to_array($order->lines, false)),
            'total' => $order->lines->total()->jsonSerialize(),
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->fields;
    }
}
