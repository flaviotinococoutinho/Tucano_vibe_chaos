<?php

declare(strict_types=1);

namespace Commerce\Inventory\Adapter;

use Commerce\Inventory\Adapter\Driven\FlaggedStrategies;
use Commerce\Inventory\Adapter\Driven\OptimisticStockHolder;
use Commerce\Inventory\Adapter\Driven\PostgresFulfillmentCenters;
use Commerce\Inventory\Adapter\Driven\PostgresReservations;
use Commerce\Inventory\Adapter\Driven\StrategicStockHolder;
use Commerce\Inventory\Adapter\Driving\Http\ChooseReservationStrategy;
use Commerce\Inventory\Application\Port\Driven\ForChoosingStrategy;
use Commerce\Inventory\Application\Port\Driven\ForFindingFulfillmentCenters;
use Commerce\Inventory\Application\Port\Driven\ForHoldingStock;
use Commerce\Inventory\Application\Port\Driven\ForRecordingReservations;
use Commerce\Inventory\Application\Port\Driving\ForCommittingStock;
use Commerce\Inventory\Application\Port\Driving\ForReleasingStock;
use Commerce\Inventory\Application\Port\Driving\ForReservingStock;
use Commerce\Inventory\Application\UseCase\CommitStock;
use Commerce\Inventory\Application\UseCase\ReleaseStock;
use Commerce\Inventory\Application\UseCase\ReserveStock;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/** Plugs the Inventory ports into their adapters. */
final class InventoryServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForReservingStock::class => ReserveStock::class,
        ForReleasingStock::class => ReleaseStock::class,
        ForCommittingStock::class => CommitStock::class,
        ForHoldingStock::class => StrategicStockHolder::class,
        ForChoosingStrategy::class => FlaggedStrategies::class,
        ForRecordingReservations::class => PostgresReservations::class,
        ForFindingFulfillmentCenters::class => PostgresFulfillmentCenters::class,
    ];

    public function register(): void
    {
        $this->app->when(OptimisticStockHolder::class)->needs('$attempts')->giveConfig('inventory.optimistic_hold_attempts');
    }

    public function boot(Router $router): void
    {
        // What Inventory offers to the routes of other packages: a route that reserves
        // stock says so by this name, and never imports a class of this package.
        $router->aliasMiddleware('reserves-stock', ChooseReservationStrategy::class);
    }
}
