<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driven;

use Commerce\Inventory\Application\Port\Driving\ForReleasingStock;
use Commerce\Inventory\Application\Port\Driving\ForReservingStock as Inventory;
use Commerce\Inventory\Application\StockItem;
use Commerce\Inventory\Application\StockRequest;
use Commerce\Ordering\Application\Port\Driven\ForReservingStock;
use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Shared\Application\Isolation;
use DateTimeImmutable;

/**
 * A driven port of Ordering answered by the driving port of Inventory. The two
 * modules share a process today; if Inventory ever becomes a service of its own,
 * this adapter is the only code that changes.
 */
final readonly class InventoryStockReservations implements ForReservingStock
{
    public function __construct(private Inventory $inventory, private ForReleasingStock $releases) {}

    public function requiredIsolation(): Isolation
    {
        return $this->inventory->requiredIsolation();
    }

    public function reserve(OrderId $order, OrderLines $lines, ShippingAddress $destination, DateTimeImmutable $until): FulfillmentCenterCode
    {
        $items = array_map(
            static fn(OrderLine $line): StockItem => new StockItem((string) $line->sku, $line->quantity->value),
            iterator_to_array($lines, false),
        );
        $reserved = $this->inventory->reserve(new StockRequest($order->toString(), $items, $destination->state->value, $until));

        return FulfillmentCenterCode::of($reserved->fulfillmentCenter);
    }

    public function release(OrderId $order): void
    {
        $this->releases->release($order->toString());
    }
}
