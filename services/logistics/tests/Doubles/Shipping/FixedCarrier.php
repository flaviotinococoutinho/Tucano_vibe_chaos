<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\Port\Driven\ForChoosingCarriers;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Shipment\CarrierCode;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Domain\DomainError;

/** Always the same carrier, and it remembers the weights it was asked about; or the refusal the test sets. */
final class FixedCarrier implements ForChoosingCarriers
{
    /** @var list<int> */
    public private(set) array $weighed = [];

    private ?DomainError $refusal = null;

    public function __construct(private readonly string $code) {}

    public function refuseWith(DomainError $refusal): void
    {
        $this->refusal = $refusal;
    }

    public function choose(FulfillmentCenterCode $origin, Address $destination, Parcels $parcels): CarrierCode
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }
        $this->weighed[] = $parcels->totalWeight()->grams();

        return CarrierCode::of($this->code);
    }
}
