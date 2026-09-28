<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use Commerce\Ordering\Domain\Customer\Customer;
use DateTimeImmutable;
use Tucano\SharedKernel\Address\Address;

/**
 * The full state of an order in one immutable object (Memento). Persistence
 * reads and rebuilds orders through it, so the aggregate needs no getters.
 */
final readonly class OrderSnapshot
{
    public function __construct(
        public OrderId $id,
        public OrderNumber $number,
        public Customer $customer,
        public Address $address,
        public OrderLines $lines,
        public FulfillmentCenterCode $fulfillmentCenter,
        public OrderStatus $status,
        public DateTimeImmutable $placedAt,
        public DateTimeImmutable $reservationExpiresAt,
        public int $version,
        /** Known once the order ships; orders shipped before it was kept have none. */
        public ?TrackingCode $trackingCode,
    ) {}
}
