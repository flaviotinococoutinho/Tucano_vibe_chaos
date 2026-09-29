<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Http;

use Commerce\Ordering\Application\Port\Driving\ForPlacingOrders;
use Commerce\Ordering\Domain\Error\ProductOfAnotherStore;
use Commerce\Shared\Application\Idempotency\Outcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PlaceOrderController
{
    public function __construct(private ForPlacingOrders $orders) {}

    public function __invoke(PlaceOrderRequest $request): JsonResponse
    {
        try {
            $placed = $this->orders->placeOrder($request->toCommand());
        } catch (ProductOfAnotherStore $refused) {
            throw self::fieldErrorsOf($refused);
        }
        $headers = [
            // Relative to the request path, so it also resolves behind the /api/commerce prefix of Kong.
            'Location' => 'orders/' . $placed->order->orderId(),
        ];
        if ($placed->outcome === Outcome::Replayed) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return new JsonResponse($placed->order->toArray(), Response::HTTP_CREATED, $headers);
    }

    /**
     * An item of another store is a mistake in the request, answered like any other field
     * that is wrong (422 with the errors by field): on the SKU of each item the store refused,
     * where the request lists it.
     */
    private static function fieldErrorsOf(ProductOfAnotherStore $refused): ValidationException
    {
        $errors = [];
        foreach ($refused->refusals as $place => $reason) {
            $errors[sprintf('items.%d.sku', $place)] = [$reason];
        }

        return ValidationException::withMessages($errors);
    }
}
