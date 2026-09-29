<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Http;

use Commerce\Ordering\Application\Port\Driving\ForViewingOrders;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\OrderId;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * GET /v1/customers/{customerId}/orders/{orderId}: the order of UC-ORD-05 with its history,
 * for the customer who placed it. Any other case answers the 404 of an order that does not
 * exist. Only the BFF calls it, on the internal network: the edge closes the customer routes.
 */
final readonly class ViewCustomerOrderController
{
    public function __construct(private ForViewingOrders $orders) {}

    public function __invoke(string $customerId, string $orderId): JsonResponse
    {
        try {
            $customer = CustomerId::fromString($customerId);
            $order = OrderId::fromString($orderId);
        } catch (InvalidArgumentException) {
            // Ids that cannot exist are simply not found, whichever of the two it is.
            throw OrderNotFound::withId($orderId);
        }

        return new JsonResponse($this->orders->viewCustomerOrder($customer, $order)->toArray());
    }
}
