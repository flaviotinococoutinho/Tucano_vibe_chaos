<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter;

use Commerce\Inventory\Adapter\Driven\AtomicStockHolder;
use Commerce\Inventory\Adapter\Driven\PostgresFulfillmentCenters;
use Commerce\Inventory\Adapter\Driven\PostgresReservations;
use Commerce\Inventory\Application\Port\Driven\ForFindingFulfillmentCenters;
use Commerce\Inventory\Application\Port\Driven\ForHoldingStock;
use Commerce\Inventory\Application\Port\Driven\ForRecordingReservations;
use Commerce\Inventory\Application\Port\Driving\ForReservingStock;
use Commerce\Inventory\Application\UseCase\ReserveStock;
use Illuminate\Support\ServiceProvider;

/** Plugs the Inventory ports into their adapters. */
final class InventoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForReservingStock::class => ReserveStock::class,
        ForHoldingStock::class => AtomicStockHolder::class,
        ForRecordingReservations::class => PostgresReservations::class,
        ForFindingFulfillmentCenters::class => PostgresFulfillmentCenters::class,
    ];
}
