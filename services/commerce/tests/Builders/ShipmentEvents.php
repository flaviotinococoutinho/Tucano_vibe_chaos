<?php

declare(strict_types=1);

namespace Tests\Builders;

use Ramsey\Uuid\Uuid;

/** Shipment events as Logistics publishes them on logistics.shipments.v2, in the shape of contracts/events/logistics.shipment.*. */
final class ShipmentEvents
{
    public const string SHIPMENT = '01999a30-5a6b-7c8d-9e0f-1a2b3c4d5e6f';

    private function __construct() {}

    /** @param array<string, mixed> $details what the step adds to the data, like the attempt of a delivery */
    public static function of(string $step, string $orderId, string $time = '2026-09-27T15:00:00.000Z', ?string $eventId = null, array $details = []): string
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
            'data' => ['shipmentId' => self::SHIPMENT, 'trackingCode' => 'TX02PWW6JFR5G00', 'orderId' => $orderId, ...$details],
        ], JSON_THROW_ON_ERROR);
    }
}
