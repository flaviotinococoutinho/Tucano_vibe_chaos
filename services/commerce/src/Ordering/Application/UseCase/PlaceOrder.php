<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\UseCase;

use Commerce\Ordering\Application\OrderDetails;
use Commerce\Ordering\Application\PlacedOrder;
use Commerce\Ordering\Application\PlaceOrderCommand;
use Commerce\Ordering\Application\Port\Driven\ForFindingProducts;
use Commerce\Ordering\Application\Port\Driven\ForNumberingOrders;
use Commerce\Ordering\Application\Port\Driven\ForReservingStock;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Application\Port\Driving\ForPlacingOrders;
use Commerce\Ordering\Application\RequestedItem;
use Commerce\Ordering\Domain\Error\ProductOfAnotherStore;
use Commerce\Ordering\Domain\Error\ProductUnavailable;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\ReservationWindow;
use Commerce\Ordering\Domain\Product\CatalogProduct;
use Commerce\Ordering\Domain\Store\StoreSlug;
use Commerce\Shared\Application\Idempotency\Outcome;
use Commerce\Shared\Application\Port\Driven\ForPublishingEvents;
use Commerce\Shared\Application\Port\Driven\ForRememberingRequests;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Time\Clock;

#[UseCase('UC-ORD-01')]
final readonly class PlaceOrder implements ForPlacingOrders
{
    private const string SCOPE = 'orders.place';

    public function __construct(
        private ForRunningTransactions $transactions,
        private ForRememberingRequests $requests,
        private ForFindingProducts $products,
        private ForReservingStock $stock,
        private ForNumberingOrders $numbers,
        private ForStoringOrders $orders,
        private ForPublishingEvents $events,
        private ReservationWindow $reservationWindow,
        private Clock $clock,
    ) {}

    public function placeOrder(PlaceOrderCommand $command): PlacedOrder
    {
        // Key, stock, order and event commit together, or nothing does.
        return $this->transactions->run(fn(): PlacedOrder => $this->place($command), $this->stock->requiredIsolation());
    }

    private function place(PlaceOrderCommand $command): PlacedOrder
    {
        $stored = $this->requests->recall(self::SCOPE, $command->idempotencyKey, $command->fingerprint());
        if ($stored !== null) {
            return new PlacedOrder(OrderDetails::fromStored($stored), Outcome::Replayed);
        }

        $lines = $this->pricedLines($command->store, $command->items);
        $id = OrderId::generate();
        $placedAt = $this->clock->now();
        $expiresAt = $this->reservationWindow->endsAt($placedAt);
        $center = $this->stock->reserve($id, $lines, $command->address, $expiresAt);

        $order = Order::place($id, $this->numbers->next(), $command->store, $command->customer, $command->address, $lines, $center, $placedAt, $expiresAt);
        $this->orders->add($order);
        $this->events->publish(...$order->releaseEvents());

        $details = OrderDetails::of($order->toSnapshot());
        $this->requests->remember(self::SCOPE, $command->idempotencyKey, $details->toArray());

        return new PlacedOrder($details, Outcome::Fresh);
    }

    /**
     * Checks every item against the local catalog copy and freezes today's price in the line:
     * a product the copy does not have stops the order at once; then every item has to be a
     * product of the store, and the refusal names all that are not; then each has to be on sale.
     *
     * @param non-empty-list<RequestedItem> $items
     */
    private function pricedLines(StoreSlug $store, array $items): OrderLines
    {
        $found = $this->products->bySku(...array_map(static fn(RequestedItem $item) => $item->sku, $items));
        $products = array_map(static fn(RequestedItem $item): CatalogProduct => $found[(string) $item->sku] ?? throw ProductUnavailable::unknown($item->sku), $items);
        $strangers = array_filter($products, static fn(CatalogProduct $product): bool => !$product->belongsTo($store));
        if ($strangers !== []) {
            // Keyed by the place of each item in the order, which is how the refusal names them.
            throw ProductOfAnotherStore::in($store, array_map(static fn(CatalogProduct $product) => $product->sku, $strangers));
        }

        return OrderLines::of(...array_map(static fn(CatalogProduct $product, RequestedItem $item): OrderLine => OrderLine::of($product, $item->quantity), $products, $items));
    }
}
