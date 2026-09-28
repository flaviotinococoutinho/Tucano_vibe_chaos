<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Application\UseCase;

use Logistics\CarrierSelection\Application\CarrierRequest;
use Logistics\CarrierSelection\Application\ChosenCarrier;
use Logistics\CarrierSelection\Application\Port\Driven\ForChoosingDispatchMode;
use Logistics\CarrierSelection\Application\Port\Driven\ForFindingCarriers;
use Logistics\CarrierSelection\Application\Port\Driven\ForLocatingFulfillmentCenters;
use Logistics\CarrierSelection\Application\Port\Driving\ForChoosingCarriers;
use Logistics\CarrierSelection\Domain\Consignment;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * Runs the carrier chain of the dispatch mode of the moment over the carriers
 * table. Operations can take the own fleet out of the chain; partners always
 * close it, so a consignment always gets a carrier.
 */
#[UseCase('UC-SHP-02')]
final readonly class ChooseCarrier implements ForChoosingCarriers
{
    public function __construct(
        private ForFindingCarriers $carriers,
        private ForLocatingFulfillmentCenters $centers,
        private ForChoosingDispatchMode $dispatch,
    ) {}

    public function choose(CarrierRequest $request): ChosenCarrier
    {
        $consignment = new Consignment($this->centers->stateOf($request->originCenter), $request->destinationState, $request->weightGrams);

        return new ChosenCarrier($this->dispatch->current()->rules()->choose($consignment, $this->carriers->all())->code);
    }
}
