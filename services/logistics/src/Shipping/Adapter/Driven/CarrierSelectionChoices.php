<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Logistics\CarrierSelection\Application\CarrierRequest;
use Logistics\CarrierSelection\Application\Port\Driving\ForChoosingCarriers as CarrierSelection;
use Logistics\Shipping\Application\Port\Driven\ForChoosingCarriers;
use Logistics\Shipping\Domain\Destination\Destination;
use Logistics\Shipping\Domain\Error\NoCarrierChosen;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Shipment\CarrierCode;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Tucano\SharedKernel\Domain\DomainError;

/**
 * A driven port of Shipping answered by the driving port of Carrier Selection.
 * Both run in this process today; if the carrier choice ever becomes a service
 * of its own, this adapter is the only code that changes.
 */
final readonly class CarrierSelectionChoices implements ForChoosingCarriers
{
    public function __construct(private CarrierSelection $carrierSelection) {}

    public function choose(FulfillmentCenterCode $origin, Destination $destination, Parcels $parcels): CarrierCode
    {
        try {
            $chosen = $this->carrierSelection->choose(new CarrierRequest(
                (string) $origin,
                $destination->state->value,
                $parcels->totalWeight()->grams(),
            ));
        } catch (DomainError $refusal) {
            // Carrier selection's errors stop here, like its types: Shipping only knows its own.
            throw NoCarrierChosen::because($refusal);
        }

        return CarrierCode::of($chosen->code);
    }
}
