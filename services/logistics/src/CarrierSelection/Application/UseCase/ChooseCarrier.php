<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Application\UseCase;

use Logistics\CarrierSelection\Application\CarrierRequest;
use Logistics\CarrierSelection\Application\ChosenCarrier;
use Logistics\CarrierSelection\Application\Port\Driven\ForFindingCarriers;
use Logistics\CarrierSelection\Application\Port\Driven\ForLocatingFulfillmentCenters;
use Logistics\CarrierSelection\Application\Port\Driving\ForChoosingCarriers;
use Logistics\CarrierSelection\Domain\Consignment;
use Logistics\CarrierSelection\Domain\Rule\CarrierRule;
use Logistics\CarrierSelection\Domain\Rule\HeavyFreight;
use Logistics\CarrierSelection\Domain\Rule\OwnFleet;
use Logistics\CarrierSelection\Domain\Rule\RegularPartners;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * Runs the carrier chain over the carriers table. The ops toggle takes the own
 * fleet out of the chain: when it is off, or when flagd does not answer, the
 * partners take everything, which always works.
 */
#[UseCase('UC-SHP-02')]
final readonly class ChooseCarrier implements ForChoosingCarriers
{
    private const string OWN_FLEET_DISPATCH = 'logistics.own-fleet-dispatch';

    public function __construct(
        private ForFindingCarriers $carriers,
        private ForLocatingFulfillmentCenters $centers,
        private FeatureFlags $flags,
    ) {}

    public function choose(CarrierRequest $request): ChosenCarrier
    {
        $consignment = new Consignment($this->centers->stateOf($request->originCenter), $request->destinationState, $request->weightGrams);

        return new ChosenCarrier($this->rules()->choose($consignment, $this->carriers->all())->code);
    }

    private function rules(): CarrierRule
    {
        $partners = new RegularPartners(new HeavyFreight());

        return $this->flags->enabled(self::OWN_FLEET_DISPATCH) ? new OwnFleet($partners) : $partners;
    }
}
