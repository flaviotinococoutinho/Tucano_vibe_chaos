<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter;

use Commerce\Payments\Adapter\Driven\BreakerGuardedGateway;
use Commerce\Payments\Adapter\Driven\OrderingPayableOrders;
use Commerce\Payments\Adapter\Driven\PayFakeGateway;
use Commerce\Payments\Adapter\Driven\PostgresPayments;
use Commerce\Payments\Adapter\Driving\Http\PayOrderController;
use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Commerce\Payments\Application\Port\Driven\ForFindingPayableOrders;
use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Application\Port\Driving\ForPayingOrders;
use Commerce\Payments\Application\UseCase\PayOrder;
use Commerce\Shared\Adapter\Driven\CircuitBreaker\RedisCircuitBreaker;
use Commerce\Shared\Adapter\Driving\Http\RequireIdempotencyKey;
use GuzzleHttp\Client;
use Illuminate\Redis\RedisManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Tucano\FeatureFlags\FeatureFlags;

/** Plugs the Payments ports into their adapters and registers its routes. */
final class PaymentsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForPayingOrders::class => PayOrder::class,
        ForStoringPayments::class => PostgresPayments::class,
        ForFindingPayableOrders::class => OrderingPayableOrders::class,
    ];

    public function register(): void
    {
        // The provider behind a circuit breaker: a decorator of the same port.
        $this->app->bind(ForChargingCards::class, fn(): ForChargingCards => new BreakerGuardedGateway(
            new PayFakeGateway(
                new Client(['base_uri' => (string) config('payments.payfake.url')]),
                $this->app->make(FeatureFlags::class),
                (int) config('payments.payfake.timeout_ms'),
            ),
            new RedisCircuitBreaker(
                $this->app->make(RedisManager::class),
                $this->app->make(LoggerInterface::class),
                'payfake',
                (int) config('payments.circuit.failure_threshold'),
                (int) config('payments.circuit.window_seconds'),
                (int) config('payments.circuit.open_seconds'),
            ),
        ));
    }

    public function boot(Router $router): void
    {
        $router->middleware('api')->group(static function (Router $router): void {
            $router->post('/v1/orders/{orderId}/payments', PayOrderController::class)->middleware(RequireIdempotencyKey::class);
        });
    }
}
