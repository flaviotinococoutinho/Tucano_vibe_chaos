<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Shared\Application\Idempotency\IdempotencyKey;

final readonly class PlaceOrderCommand
{
    /** @param non-empty-list<RequestedItem> $items */
    public function __construct(
        public IdempotencyKey $idempotencyKey,
        public Customer $customer,
        public ShippingAddress $address,
        public array $items,
    ) {}

    /**
     * SHA-256 of the content, in a fixed order: the same order sent twice has the
     * same fingerprint, whatever the JSON key order or whitespace of the requests.
     */
    public function fingerprint(): string
    {
        $address = $this->address;

        return hash('sha256', json_encode([
            'customer' => [(string) $this->customer->id, (string) $this->customer->name, (string) $this->customer->email],
            'address' => [
                $address->street, $address->number, $address->complement, $address->district, $address->city,
                $address->state->value, (string) $address->postalCode,
                $address->coordinates?->latitude, $address->coordinates?->longitude,
            ],
            'items' => array_map(static fn(RequestedItem $item): array => [(string) $item->sku, $item->quantity->value], $this->items),
        ], JSON_THROW_ON_ERROR));
    }
}
