<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use DateMalformedStringException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use JsonException;
use Logistics\Shipping\Adapter\CarrierFakeEvents;
use Logistics\Shipping\Application\CarrierEvent;
use Logistics\Shipping\Application\Port\Driven\ForTrackingPickups;
use Logistics\Shipping\Domain\Error\CarrierUnreachable;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Psr\Log\LoggerInterface;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Messaging\EventFields;
use ValueError;

/**
 * The tracking history of CarrierFake: the pickup is found by the reference
 * Logistics gave it (the shipment id, like the Idempotency-Key of the booking),
 * and its events come in the shape of the webhooks. A pickup the carrier does
 * not have, or no longer has, is an empty history.
 */
final readonly class CarrierFakeTracking implements ForTrackingPickups
{
    public function __construct(private ClientInterface $http, private int $timeoutMilliseconds, private int $connectTimeoutMilliseconds, private LoggerInterface $logger) {}

    public function eventsOf(ShipmentId $shipment): array
    {
        $pickups = $this->get('/carriers/v1/pickups', ['reference' => $shipment->toString()])?->objects('data') ?? [];
        if ($pickups === []) {
            return [];
        }
        $history = $this->get(sprintf('/carriers/v1/pickups/%s/events', rawurlencode($pickups[0]->text('id'))), [])?->objects('data') ?? [];

        return array_values(array_filter(array_map($this->read(...), $history)));
    }

    /** One unreadable event is skipped and logged: the rest of the history still catches the journey up. */
    private function read(EventFields $event): ?CarrierEvent
    {
        try {
            return CarrierFakeEvents::toCarrierEvent($event);
        } catch (InvalidArgumentException|DateMalformedStringException|ValueError|DomainError $unreadable) {
            $this->logger->warning('Carrier history has an event Shipping cannot read: {message}', ['message' => $unreadable->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, string> $query
     *
     * @return EventFields|null null for a 404: the carrier has nothing under that path
     */
    private function get(string $path, array $query): ?EventFields
    {
        try {
            $response = $this->http->request('GET', $path, [
                'query' => $query,
                'headers' => ['X-Correlation-Id' => (string) Context::get('correlation_id', '')],
                'timeout' => $this->timeoutMilliseconds / 1_000,
                'connect_timeout' => min($this->connectTimeoutMilliseconds, $this->timeoutMilliseconds) / 1_000,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $failure) {
            throw CarrierUnreachable::because($failure->getMessage(), $failure);
        }
        $status = $response->getStatusCode();
        if ($status === 404) {
            return null;
        }
        if ($status !== 200) {
            throw CarrierUnreachable::because(sprintf('HTTP %d on %s', $status, $path));
        }
        try {
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $unreadable) {
            throw CarrierUnreachable::because('an answer that is not JSON', $unreadable);
        }

        return new EventFields(is_array($body) ? $body : []);
    }
}
