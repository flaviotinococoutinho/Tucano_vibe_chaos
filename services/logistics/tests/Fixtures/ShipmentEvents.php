<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Ramsey\Uuid\Uuid;
use Tucano\Messaging\Kafka\ReceivedMessage;

/** Shipment events as Logistics publishes them on logistics.shipments.v2, in the shape of contracts/events/logistics.shipment.*. */
final class ShipmentEvents
{
    public const string SHIPMENT = '01999a30-5a6b-7c8d-9e0f-1a2b3c4d5e6f';

    public const string ORDER = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    public const string TRACKING_CODE = 'TX02PWW6JFR5G00';

    public const string STORE = 'sabia';

    private function __construct() {}

    /**
     * @param array<string, mixed> $details what the step adds to the data
     * @param ?string $store null for a shipment from before the stores, whose events have no store
     */
    public static function of(string $step, array $details = [], string $time = '2026-09-27T15:00:00.000Z', ?string $eventId = null, ?string $store = self::STORE): string
    {
        return (string) json_encode([
            'specversion' => '1.0',
            'id' => $eventId ?? Uuid::uuid7()->toString(),
            'source' => '/logistics',
            'type' => 'tucano.logistics.shipment.' . $step,
            'subject' => self::SHIPMENT,
            'time' => $time,
            'datacontenttype' => 'application/json',
            'correlationid' => 'req-12#4',
            'data' => [
                ...($store === null ? [] : ['store' => $store]),
                'shipmentId' => self::SHIPMENT,
                'trackingCode' => self::TRACKING_CODE,
                'orderId' => self::ORDER,
                ...$details,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> the details of shipment.created */
    public static function created(): array
    {
        return [
            'carrier' => 'tucano-express',
            'origin' => 'BHZ1',
            'destination' => [
                'divisions' => [['kind' => 'state', 'code' => 'MG', 'name' => 'Minas Gerais'], ['kind' => 'municipality', 'code' => '3106705', 'name' => 'Betim']],
                'postalCode' => '32669000',
            ],
            'parcels' => [['weightGrams' => 1100, 'dimensions' => ['lengthMm' => 240, 'widthMm' => 170, 'heightMm' => 40]]],
            'totalWeightGrams' => 1100,
        ];
    }

    public static function message(string $payload): ReceivedMessage
    {
        return new ReceivedMessage('logistics.shipments.v2', 1, 3, self::SHIPMENT, $payload);
    }
}
