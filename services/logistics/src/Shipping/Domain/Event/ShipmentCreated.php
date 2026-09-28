<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Event;

use Logistics\Shipping\Domain\Parcel\Parcel;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Shipment\CarrierCode;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Logistics\Shipping\Domain\Shipment\StatusTransition;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Address\DivisionKind;

/**
 * The destination goes out down to the municipality, with the CEP: the topic
 * keeps events for a week, and no consumer needs the thoroughfare, the number
 * or the name of the recipient.
 */
final readonly class ShipmentCreated extends ShipmentEvent
{
    public function __construct(
        ShipmentReference $shipment,
        StatusTransition $transition,
        private CarrierCode $carrier,
        private FulfillmentCenterCode $origin,
        private Address $destination,
        private Parcels $parcels,
    ) {
        parent::__construct($shipment, $transition);
    }

    protected function details(): array
    {
        return [
            'carrier' => (string) $this->carrier,
            'origin' => (string) $this->origin,
            'destination' => [
                'divisions' => $this->destination->divisions->upTo(DivisionKind::Municipality)->toArray(),
                'postalCode' => (string) $this->destination->postalCode,
            ],
            'parcels' => array_map(static fn(Parcel $parcel): array => [
                'weightGrams' => $parcel->weight->grams(),
                'dimensions' => [
                    'lengthMm' => $parcel->dimensions->lengthMm,
                    'widthMm' => $parcel->dimensions->widthMm,
                    'heightMm' => $parcel->dimensions->heightMm,
                ],
            ], iterator_to_array($this->parcels, false)),
            'totalWeightGrams' => $this->parcels->totalWeight()->grams(),
        ];
    }
}
