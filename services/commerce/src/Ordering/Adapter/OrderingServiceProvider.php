<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter;

use Commerce\Ordering\Adapter\Driven\InventoryStockReservations;
use Commerce\Ordering\Adapter\Driven\MongoOrderViews;
use Commerce\Ordering\Adapter\Driven\PaymentsRefunds;
use Commerce\Ordering\Adapter\Driven\PostgresCatalogSnapshots;
use Commerce\Ordering\Adapter\Driven\PostgresOrders;
use Commerce\Ordering\Adapter\Driven\SnowflakeOrderNumbers;
use Commerce\Ordering\Adapter\Driving\Console\ExpireOrdersWorker;
use Commerce\Ordering\Adapter\Driving\Console\ProjectOrderViews;
use Commerce\Ordering\Adapter\Driving\Console\SyncCatalog;
use Commerce\Ordering\Adapter\Driving\Console\SyncShipments;
use Commerce\Ordering\Adapter\Driving\Http\ListOrdersController;
use Commerce\Ordering\Adapter\Driving\Http\PlaceOrderController;
use Commerce\Ordering\Adapter\Driving\Http\ViewCustomerOrderController;
use Commerce\Ordering\Adapter\Driving\Http\ViewOrderController;
use Commerce\Ordering\Application\Port\Driven\ForFindingProducts;
use Commerce\Ordering\Application\Port\Driven\ForNumberingOrders;
use Commerce\Ordering\Application\Port\Driven\ForReadingOrderViews;
use Commerce\Ordering\Application\Port\Driven\ForRefundingOrders;
use Commerce\Ordering\Application\Port\Driven\ForReservingStock;
use Commerce\Ordering\Application\Port\Driven\ForStoringCatalogCopies;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrderViews;
use Commerce\Ordering\Application\Port\Driving\ForExpiringOrders;
use Commerce\Ordering\Application\Port\Driving\ForFollowingShipments;
use Commerce\Ordering\Application\Port\Driving\ForListingOrders;
use Commerce\Ordering\Application\Port\Driving\ForPlacingOrders;
use Commerce\Ordering\Application\Port\Driving\ForProjectingOrderViews;
use Commerce\Ordering\Application\Port\Driving\ForSettlingOrderPayments;
use Commerce\Ordering\Application\Port\Driving\ForSyncingCatalog;
use Commerce\Ordering\Application\Port\Driving\ForViewingOrders;
use Commerce\Ordering\Application\UseCase\ExpireOrders;
use Commerce\Ordering\Application\UseCase\FollowShipment;
use Commerce\Ordering\Application\UseCase\ListOrders;
use Commerce\Ordering\Application\UseCase\PlaceOrder;
use Commerce\Ordering\Application\UseCase\ProjectOrderView;
use Commerce\Ordering\Application\UseCase\SettleOrderPayment;
use Commerce\Ordering\Application\UseCase\SyncCatalogProduct;
use Commerce\Ordering\Application\UseCase\ViewOrder;
use Commerce\Ordering\Domain\Order\ReservationWindow;
use Commerce\Shared\Adapter\Driving\Http\RequireIdempotencyKey;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/** Plugs the Ordering ports into their adapters and registers its routes and commands. */
final class OrderingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForPlacingOrders::class => PlaceOrder::class,
        ForFollowingShipments::class => FollowShipment::class,
        ForRefundingOrders::class => PaymentsRefunds::class,
        ForViewingOrders::class => ViewOrder::class,
        ForListingOrders::class => ListOrders::class,
        ForProjectingOrderViews::class => ProjectOrderView::class,
        ForSyncingCatalog::class => SyncCatalogProduct::class,
        ForExpiringOrders::class => ExpireOrders::class,
        ForSettlingOrderPayments::class => SettleOrderPayment::class,
        ForStoringOrders::class => PostgresOrders::class,
        ForStoringOrderViews::class => MongoOrderViews::class,
        ForReadingOrderViews::class => MongoOrderViews::class,
        ForFindingProducts::class => PostgresCatalogSnapshots::class,
        ForStoringCatalogCopies::class => PostgresCatalogSnapshots::class,
        ForNumberingOrders::class => SnowflakeOrderNumbers::class,
        ForReservingStock::class => InventoryStockReservations::class,
    ];

    public function register(): void
    {
        $this->app->singleton(
            ReservationWindow::class,
            static fn(): ReservationWindow => ReservationWindow::ofMinutes((int) config('ordering.reservation_minutes')),
        );
    }

    public function boot(Router $router): void
    {
        $this->commands([SyncCatalog::class, ExpireOrdersWorker::class, SyncShipments::class, ProjectOrderViews::class]);
        $router->middleware('api')->group(static function (Router $router): void {
            $router->post('/v1/orders', PlaceOrderController::class)->middleware([RequireIdempotencyKey::class, 'reserves-stock']);
            $router->get('/v1/orders/{orderId}', ViewOrderController::class);
            // The customer routes: only the BFF calls them, and Kong closes them at the edge (ADR 0030).
            $router->get('/v1/customers/{customerId}/orders', ListOrdersController::class);
            $router->get('/v1/customers/{customerId}/orders/{orderId}', ViewCustomerOrderController::class);
        });
    }
}
