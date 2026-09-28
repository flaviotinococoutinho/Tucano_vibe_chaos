<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Context;
use Logistics\Shipping\Application\PickupOrder;
use Logistics\Shipping\Application\Port\Driven\ForSchedulingPickups;
use Logistics\Shipping\Domain\Error\PickupNotBooked;
use Logistics\Shipping\Domain\Error\PickupRefused;

/**
 * The anticorruption layer with CarrierFake (contracts/http/carriers.openapi.yaml):
 * its vocabulary (pickup, parcel) stops here. The shipment id goes as the
 * Idempotency-Key and as the reference, so a booking repeated after a lost
 * answer books the same pickup, and every event comes back with the id.
 */
final readonly class CarrierFakePickups implements ForSchedulingPickups
{
    public function __construct(private ClientInterface $http, private int $timeoutMilliseconds, private int $connectTimeoutMilliseconds) {}

    public function schedule(PickupOrder $order): void
    {
        $shipment = $order->shipment;
        try {
            $response = $this->http->request('POST', '/carriers/v1/pickups', [
                'headers' => [
                    'Idempotency-Key' => $shipment->reference->id->toString(),
                    'X-Correlation-Id' => (string) Context::get('correlation_id', ''),
                ],
                'json' => [
                    'carrier' => (string) $shipment->carrier,
                    'reference' => $shipment->reference->id->toString(),
                    'trackingCode' => (string) $shipment->reference->trackingCode,
                    'origin' => ['center' => (string) $shipment->origin, 'state' => $order->originState->value],
                    // The contract is the carrier's: it calls the municipality a city.
                    'destination' => [
                        'city' => $shipment->destination->municipality()->name,
                        'state' => $shipment->destination->state()->value,
                        'postalCode' => (string) $shipment->destination->postalCode,
                    ],
                    'parcels' => count($shipment->parcels),
                    'weightGrams' => $shipment->parcels->totalWeight()->grams(),
                ],
                'timeout' => $this->timeoutMilliseconds / 1_000,
                'connect_timeout' => min($this->connectTimeoutMilliseconds, $this->timeoutMilliseconds) / 1_000,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $failure) {
            throw PickupNotBooked::because($failure->getMessage(), $failure);
        }

        $status = $response->getStatusCode();
        if ($status >= 500) {
            throw PickupNotBooked::because(sprintf('HTTP %d', $status));
        }
        if ($status >= 400) {
            $problem = json_decode((string) $response->getBody(), true);
            $detail = is_array($problem) && is_string($problem['detail'] ?? null) ? $problem['detail'] : sprintf('HTTP %d', $status);

            throw PickupRefused::because($detail);
        }
    }
}
