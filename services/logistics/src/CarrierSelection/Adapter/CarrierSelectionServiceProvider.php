<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Adapter;

use Illuminate\Support\ServiceProvider;
use Logistics\CarrierSelection\Adapter\Driven\PostgresCarriers;
use Logistics\CarrierSelection\Adapter\Driven\PostgresFulfillmentCenters;
use Logistics\CarrierSelection\Application\Port\Driven\ForFindingCarriers;
use Logistics\CarrierSelection\Application\Port\Driven\ForLocatingFulfillmentCenters;
use Logistics\CarrierSelection\Application\Port\Driving\ForChoosingCarriers;
use Logistics\CarrierSelection\Application\UseCase\ChooseCarrier;

/** Plugs the Carrier Selection ports into their adapters. */
final class CarrierSelectionServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForChoosingCarriers::class => ChooseCarrier::class,
        ForFindingCarriers::class => PostgresCarriers::class,
        ForLocatingFulfillmentCenters::class => PostgresFulfillmentCenters::class,
    ];
}
