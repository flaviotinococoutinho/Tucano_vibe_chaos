<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter;

use Commerce\Payments\Adapter\Driven\BreakerGuardedGateway;
use Commerce\Payments\Adapter\Driven\OrderingPayableOrders;
use Commerce\Payments\Adapter\Driven\OrderingSettlements;
use Commerce\Payments\Adapter\Driven\PayFakeGateway;
use Commerce\Payments\Adapter\Driven\PostgresPayments;
use Commerce\Payments\Adapter\Driving\Console\ReconcilePaymentsWorker;
use Commerce\Payments\Adapter\Driving\Http\PayFakeWebhookController;
use Commerce\Payments\Adapter\Driving\Http\PayOrderController;
use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Commerce\Payments\Application\Port\Driven\ForFindingPayableOrders;
use Commerce\Payments\Application\Port\Driven\ForSettlingOrders;
use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Application\Port\Driving\ForPayingOrders;
use Commerce\Payments\Application\Port\Driving\ForReconcilingPayments;
use Commerce\Payments\Application\Port\Driving\ForRefundingPayments;
use Commerce\Payments\Application\Port\Driving\ForRequestingRefunds;
use Commerce\Payments\Application\Port\Driving\ForSettlingPayments;
use Commerce\Payments\Application\UseCase\PayOrder;
use Commerce\Payments\Application\UseCase\ReconcilePayments;
use Commerce\Payments\Application\UseCase\RefundPayment;
use Commerce\Payments\Application\UseCase\RequestOrderRefund;
use Commerce\Payments\Application\UseCase\SettlePayment;
use Commerce\Shared\Adapter\Driven\CircuitBreaker\RedisCircuitBreaker;
use Commerce\Shared\Adapter\Driving\Http\RequireIdempotencyKey;
use GuzzleHttp\Client;
use Illuminate\Redis\RedisManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\Messaging\Webhook\WebhookSignature;

/** Plugs the Payments ports into their adapters and registers its routes, the webhook included. */
final class PaymentsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForPayingOrders::class => PayOrder::class,
        ForStoringPayments::class => PostgresPayments::class,
        ForFindingPayableOrders::class => OrderingPayableOrders::class,
        ForSettlingPayments::class => SettlePayment::class,
        ForSettlingOrders::class => OrderingSettlements::class,
        ForReconcilingPayments::class => ReconcilePayments::class,
        ForRefundingPayments::class => RefundPayment::class,
        ForRequestingRefunds::class => RequestOrderRefund::class,
    ];

    public function register(): void
    {
        $this->app->when(ReconcilePayments::class)->needs('$quietSeconds')->giveConfig('payments.reconciliation.quiet_seconds');
        $this->app->when(ReconcilePayments::class)->needs('$lostChargeAfterSeconds')->giveConfig('payments.reconciliation.lost_charge_after_seconds');
        // The one webhook commerce receives is PayFake's, so the one signature to check is its.
        $this->app->bind(WebhookSignature::class, static fn(): WebhookSignature => new WebhookSignature((string) config('payments.payfake.webhook_secret')));
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
        $this->commands([ReconcilePaymentsWorker::class]);
        $router->middleware('api')->group(static function (Router $router): void {
            $router->post('/v1/orders/{orderId}/payments', PayOrderController::class)->middleware(RequireIdempotencyKey::class);
            // No Idempotency-Key here: the event id, through the inbox, plays that part.
            $router->post('/v1/webhooks/payfake', PayFakeWebhookController::class);
        });
    }
}
