<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Http;

use Commerce\Ordering\Application\Outcome;
use Commerce\Ordering\Application\Port\Driving\ForPlacingOrders;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class PlaceOrderController
{
    public function __construct(private ForPlacingOrders $orders) {}

    public function __invoke(PlaceOrderRequest $request): JsonResponse
    {
        $placed = $this->orders->placeOrder($request->toCommand());
        $headers = [
            // Relative to the request path, so it also resolves behind the /api/commerce prefix of Kong.
            'Location' => 'orders/' . $placed->order->orderId(),
        ];
        if ($placed->outcome === Outcome::Replayed) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return new JsonResponse($placed->order->toArray(), Response::HTTP_CREATED, $headers);
    }
}
