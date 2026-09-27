<?php

declare(strict_types=1);

namespace Tests\Doubles\Ordering;

use Commerce\Inventory\Domain\InsufficientStock;
use Commerce\Ordering\Application\Port\Driven\ForReservingStock;
use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Shared\Application\Isolation;
use DateTimeImmutable;

/** Holds everything in one center, until the test says the stock ran out. */
final class FakeStockReservations implements ForReservingStock
{
    /** @var list<string> */
    public private(set) array $reservedFor = [];

    /** @var list<string> */
    public private(set) array $releasedFor = [];

    /** @var list<string> */
    public private(set) array $committedFor = [];

    private ?InsufficientStock $shortage = null;

    public function __construct(private readonly string $center) {}

    public function requiredIsolation(): Isolation
    {
        return Isolation::ReadCommitted;
    }

    public function runOutOf(string $sku): void
    {
        $this->shortage = InsufficientStock::in([$this->center => [$sku]]);
    }

    public function reserve(OrderId $order, OrderLines $lines, ShippingAddress $destination, DateTimeImmutable $until): FulfillmentCenterCode
    {
        if ($this->shortage !== null) {
            throw $this->shortage;
        }
        $this->reservedFor[] = $order->toString();

        return FulfillmentCenterCode::of($this->center);
    }

    public function release(OrderId $order): void
    {
        $this->releasedFor[] = $order->toString();
    }

    public function commit(OrderId $order): void
    {
        $this->committedFor[] = $order->toString();
    }
}
