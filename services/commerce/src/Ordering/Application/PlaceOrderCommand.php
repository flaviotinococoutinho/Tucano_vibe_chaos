<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Shared\Application\Idempotency\IdempotencyKey;
use Tucano\SharedKernel\Address\Address;

final readonly class PlaceOrderCommand
{
    /** @param non-empty-list<RequestedItem> $items */
    public function __construct(
        public IdempotencyKey $idempotencyKey,
        public Customer $customer,
        public Address $address,
        public array $items,
    ) {}

    /**
     * SHA-256 of the content, in a fixed order: the same order sent twice has the
     * same fingerprint, whatever the JSON key order or whitespace of the requests.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'customer' => [(string) $this->customer->id, (string) $this->customer->name, (string) $this->customer->email],
            'address' => $this->address->toArray(),
            'items' => array_map(static fn(RequestedItem $item): array => [(string) $item->sku, $item->quantity->value], $this->items),
        ], JSON_THROW_ON_ERROR));
    }
}
