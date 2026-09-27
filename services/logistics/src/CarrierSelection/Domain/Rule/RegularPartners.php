<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain\Rule;

use Logistics\CarrierSelection\Domain\Carrier;
use Logistics\CarrierSelection\Domain\CarrierKind;
use Logistics\CarrierSelection\Domain\Carriers;
use Logistics\CarrierSelection\Domain\Consignment;

/** The partner with the smallest limit that still takes the weight. Heavy freight has a link of its own. */
final readonly class RegularPartners extends CarrierRule
{
    protected function pick(Consignment $consignment, Carriers $carriers): ?Carrier
    {
        return $carriers->ofKind(CarrierKind::Partner)->without(HeavyFreight::CARRIER)->thatCarry($consignment)->smallest();
    }
}
