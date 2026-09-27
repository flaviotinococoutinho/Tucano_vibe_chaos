<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driving\Http;

use Commerce\Payments\Application\Port\Driving\ForPayingOrders;
use Commerce\Payments\Domain\GatewayUnavailable;
use Commerce\Shared\Application\Idempotency\Outcome;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** 202: the charge went to the provider, and the outcome comes later by webhook. */
final readonly class PayOrderController
{
    public function __construct(private ForPayingOrders $payments) {}

    public function __invoke(PayOrderRequest $request, string $orderId): JsonResponse
    {
        try {
            $attempt = $this->payments->payOrder($request->toCommand($orderId));
        } catch (GatewayUnavailable $unavailable) {
            // Retry-After tells the client when the circuit lets calls through again.
            throw new ServiceUnavailableHttpException($unavailable->retryAfterSeconds, $unavailable->getMessage(), $unavailable);
        }
        $headers = $attempt->outcome === Outcome::Replayed ? ['Idempotent-Replayed' => 'true'] : [];

        return new JsonResponse($attempt->payment->toArray(), Response::HTTP_ACCEPTED, $headers);
    }
}
