<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain\Rule;

use Logistics\CarrierSelection\Domain\Carrier;
use Logistics\CarrierSelection\Domain\CarrierKind;
use Logistics\CarrierSelection\Domain\Carriers;
use Logistics\CarrierSelection\Domain\Consignment;

/** The Tucano vans deliver only inside the state of the center they leave from, and only what they can carry. */
final readonly class OwnFleet extends CarrierRule
{
    protected function pick(Consignment $consignment, Carriers $carriers): ?Carrier
    {
        return $consignment->staysInState() ? $carriers->ofKind(CarrierKind::OwnFleet)->thatCarry($consignment)->smallest() : null;
    }
}
