<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Http;

use Commerce\Ordering\Application\Port\Driving\ForViewingOrders;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\OrderId;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

final readonly class ViewOrderController
{
    public function __construct(private ForViewingOrders $orders) {}

    public function __invoke(string $orderId): JsonResponse
    {
        try {
            $id = OrderId::fromString($orderId);
        } catch (InvalidArgumentException) {
            // An id that cannot exist is simply not found.
            throw OrderNotFound::withId($orderId);
        }

        return new JsonResponse($this->orders->viewOrder($id)->toArray());
    }
}
