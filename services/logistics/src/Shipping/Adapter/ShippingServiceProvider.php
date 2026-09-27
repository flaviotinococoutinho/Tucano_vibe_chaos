<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter;

use Illuminate\Support\ServiceProvider;
use Logistics\Shipping\Adapter\Driven\CarrierSelectionChoices;
use Logistics\Shipping\Adapter\Driven\PostgresCancelledOrders;
use Logistics\Shipping\Adapter\Driven\PostgresCatalogSnapshots;
use Logistics\Shipping\Adapter\Driven\PostgresShipments;
use Logistics\Shipping\Adapter\Driven\SnowflakeTrackingCodes;
use Logistics\Shipping\Adapter\Driving\Console\OrderIntake;
use Logistics\Shipping\Adapter\Driving\Console\SyncCatalog;
use Logistics\Shipping\Application\Port\Driven\ForChoosingCarriers;
use Logistics\Shipping\Application\Port\Driven\ForFindingProducts;
use Logistics\Shipping\Application\Port\Driven\ForIssuingTrackingCodes;
use Logistics\Shipping\Application\Port\Driven\ForStoringCancelledOrders;
use Logistics\Shipping\Application\Port\Driven\ForStoringCatalogCopies;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\Port\Driving\ForCancellingShipments;
use Logistics\Shipping\Application\Port\Driving\ForCreatingShipments;
use Logistics\Shipping\Application\Port\Driving\ForSyncingCatalog;
use Logistics\Shipping\Application\UseCase\CancelShipment;
use Logistics\Shipping\Application\UseCase\CreateShipment;
use Logistics\Shipping\Application\UseCase\SyncCatalogProduct;

/** Plugs the Shipping ports into their adapters and registers its workers. */
final class ShippingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForCreatingShipments::class => CreateShipment::class,
        ForCancellingShipments::class => CancelShipment::class,
        ForSyncingCatalog::class => SyncCatalogProduct::class,
        ForStoringShipments::class => PostgresShipments::class,
        ForStoringCancelledOrders::class => PostgresCancelledOrders::class,
        ForFindingProducts::class => PostgresCatalogSnapshots::class,
        ForStoringCatalogCopies::class => PostgresCatalogSnapshots::class,
        ForIssuingTrackingCodes::class => SnowflakeTrackingCodes::class,
        ForChoosingCarriers::class => CarrierSelectionChoices::class,
    ];

    public function boot(): void
    {
        $this->commands([SyncCatalog::class, OrderIntake::class]);
    }
}
