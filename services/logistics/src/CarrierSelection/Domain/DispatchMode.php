<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain;

use Logistics\CarrierSelection\Domain\Rule\CarrierRule;
use Logistics\CarrierSelection\Domain\Rule\HeavyFreight;
use Logistics\CarrierSelection\Domain\Rule\OwnFleet;
use Logistics\CarrierSelection\Domain\Rule\RegularPartners;

/**
 * Who takes part in the carrier chain today. Operations decide it (the own
 * fleet may be off for a strike, a storm or a lab), and each mode knows the
 * chain it runs, so no caller needs an if on a boolean to build it.
 */
enum DispatchMode: string
{
    /** The own fleet takes what it can (same state, light parcels); partners take the rest. */
    case OwnFleetFirst = 'own_fleet_first';

    /** Partners take everything. It always works, so it is also the answer when nobody knows. */
    case PartnersOnly = 'partners_only';

    /** The rule chain of this mode. Partners always close it, because a partner always takes the parcel. */
    public function rules(): CarrierRule
    {
        $partners = new RegularPartners(new HeavyFreight());

        return match ($this) {
            self::OwnFleetFirst => new OwnFleet($partners),
            self::PartnersOnly => $partners,
        };
    }
}
