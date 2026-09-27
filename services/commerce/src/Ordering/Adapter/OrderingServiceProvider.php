<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter;

use Commerce\Ordering\Adapter\Driven\InventoryStockReservations;
use Commerce\Ordering\Adapter\Driven\PostgresCatalogSnapshots;
use Commerce\Ordering\Adapter\Driven\PostgresOrders;
use Commerce\Ordering\Adapter\Driven\SnowflakeOrderNumbers;
use Commerce\Ordering\Adapter\Driving\Http\PlaceOrderController;
use Commerce\Ordering\Adapter\Driving\Http\ViewOrderController;
use Commerce\Ordering\Application\Port\Driven\ForFindingProducts;
use Commerce\Ordering\Application\Port\Driven\ForNumberingOrders;
use Commerce\Ordering\Application\Port\Driven\ForReservingStock;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Application\Port\Driving\ForPlacingOrders;
use Commerce\Ordering\Application\Port\Driving\ForViewingOrders;
use Commerce\Ordering\Application\UseCase\PlaceOrder;
use Commerce\Ordering\Application\UseCase\ViewOrder;
use Commerce\Ordering\Domain\Order\ReservationWindow;
use Commerce\Shared\Adapter\Driving\Http\RequireIdempotencyKey;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/** Plugs the Ordering ports into their adapters and registers its routes. */
final class OrderingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForPlacingOrders::class => PlaceOrder::class,
        ForViewingOrders::class => ViewOrder::class,
        ForStoringOrders::class => PostgresOrders::class,
        ForFindingProducts::class => PostgresCatalogSnapshots::class,
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
        $router->middleware('api')->group(static function (Router $router): void {
            $router->post('/v1/orders', PlaceOrderController::class)->middleware(RequireIdempotencyKey::class);
            $router->get('/v1/orders/{orderId}', ViewOrderController::class);
        });
    }
}
