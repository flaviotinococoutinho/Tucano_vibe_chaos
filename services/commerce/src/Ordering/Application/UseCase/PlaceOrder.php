<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\UseCase;

use Commerce\Ordering\Application\OrderDetails;
use Commerce\Ordering\Application\Outcome;
use Commerce\Ordering\Application\PlacedOrder;
use Commerce\Ordering\Application\PlaceOrderCommand;
use Commerce\Ordering\Application\Port\Driven\ForFindingProducts;
use Commerce\Ordering\Application\Port\Driven\ForNumberingOrders;
use Commerce\Ordering\Application\Port\Driven\ForReservingStock;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Application\Port\Driving\ForPlacingOrders;
use Commerce\Ordering\Application\RequestedItem;
use Commerce\Ordering\Domain\Error\ProductUnavailable;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\ReservationWindow;
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
        return $this->transactions->run(function () use ($command): PlacedOrder {
            $stored = $this->requests->recall(self::SCOPE, $command->idempotencyKey, $command->fingerprint());
            if ($stored !== null) {
                return new PlacedOrder(OrderDetails::fromStored($stored), Outcome::Replayed);
            }

            $lines = $this->pricedLines($command->items);
            $id = OrderId::generate();
            $placedAt = $this->clock->now();
            $expiresAt = $this->reservationWindow->endsAt($placedAt);
            $center = $this->stock->reserve($id, $lines, $command->address, $expiresAt);

            $order = Order::place($id, $this->numbers->next(), $command->customer, $command->address, $lines, $center, $placedAt, $expiresAt);
            $this->orders->add($order);
            $this->events->publish(...$order->releaseEvents());

            $details = OrderDetails::of($order->toSnapshot());
            $this->requests->remember(self::SCOPE, $command->idempotencyKey, $details->toArray());

            return new PlacedOrder($details, Outcome::Placed);
        });
    }

    /**
     * Checks every item against the local catalog copy and freezes today's price in the line.
     *
     * @param non-empty-list<RequestedItem> $items
     */
    private function pricedLines(array $items): OrderLines
    {
        $products = $this->products->bySku(...array_map(static fn(RequestedItem $item) => $item->sku, $items));

        return OrderLines::of(...array_map(static function (RequestedItem $item) use ($products): OrderLine {
            $product = $products[(string) $item->sku] ?? throw ProductUnavailable::unknown($item->sku);

            return OrderLine::of($product, $item->quantity);
        }, $items));
    }
}
