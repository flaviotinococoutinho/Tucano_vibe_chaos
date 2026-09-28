<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use Logistics\Shipping\Application\CarrierEvent;
use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\Hub;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Messaging\EventFields;
use ValueError;

/**
 * The parcel events of CarrierFake in the language of Shipping. The webhook and
 * the tracking history carry the same JSON, so both read it here, and the
 * carrier vocabulary (parcel, visit) stops at this class. An event type
 * Shipping does not know is null (tolerant reader); a known one without what
 * it needs is unreadable.
 */
final readonly class CarrierFakeEvents
{
    private function __construct() {}

    /** @throws InvalidArgumentException|DateMalformedStringException|ValueError|DomainError when the event cannot be read */
    public static function toCarrierEvent(EventFields $event): ?CarrierEvent
    {
        $data = $event->object('data');
        $report = CarrierReport::of($event->text('id'), TrackingCode::fromString($data->text('trackingCode')), new DateTimeImmutable($event->text('createdAt')));

        return match ($event->text('type')) {
            'parcel.picked_up' => CarrierEvent::pickedUp($report),
            'parcel.hub_scanned' => CarrierEvent::hubScanned($report, Hub::named($data->text('hub'))),
            'parcel.out_for_delivery' => CarrierEvent::outForDelivery($report),
            'parcel.delivered' => CarrierEvent::delivered($report, ProofOfDelivery::of($data->text('receiverName'), $data->text('receiverDocument'))),
            'parcel.delivery_failed' => CarrierEvent::deliveryFailed($report, DeliveryFailure::from($data->text('reason'))),
            'parcel.returning' => CarrierEvent::returning($report),
            'parcel.returned' => CarrierEvent::returned($report),
            default => null,
        };
    }
}
