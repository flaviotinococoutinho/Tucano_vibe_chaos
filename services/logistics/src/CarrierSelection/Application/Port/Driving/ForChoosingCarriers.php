<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Application\Port\Driving;

use Logistics\CarrierSelection\Application\CarrierRequest;
use Logistics\CarrierSelection\Application\ChosenCarrier;
use Logistics\CarrierSelection\Domain\NoCarrierFits;
use Logistics\CarrierSelection\Domain\UnknownFulfillmentCenter;

interface ForChoosingCarriers
{
    /**
     * @throws NoCarrierFits when no link of the chain has a carrier for the weight
     * @throws UnknownFulfillmentCenter
     */
    public function choose(CarrierRequest $request): ChosenCarrier;
}
