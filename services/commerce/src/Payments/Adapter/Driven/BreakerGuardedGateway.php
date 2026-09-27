<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driven;

use Closure;
use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Commerce\Payments\Application\ProviderCharge;
use Commerce\Payments\Domain\CardToken;
use Commerce\Payments\Domain\ChargeRefused;
use Commerce\Payments\Domain\GatewayFailure;
use Commerce\Payments\Domain\GatewayUnavailable;
use Commerce\Payments\Domain\PaymentId;
use Commerce\Shared\Adapter\Driven\CircuitBreaker\RedisCircuitBreaker;
use Tucano\SharedKernel\Money\Money;

/**
 * A decorator: the same port, with a circuit breaker in front of every call. Only
 * a failure of the provider counts against it; a refused request means the
 * provider is up.
 */
final readonly class BreakerGuardedGateway implements ForChargingCards
{
    public function __construct(private ForChargingCards $gateway, private RedisCircuitBreaker $breaker) {}

    public function ensureAvailable(): void
    {
        $wait = $this->breaker->secondsUntilRetry();
        if ($wait > 0) {
            throw GatewayUnavailable::forSeconds($wait);
        }
    }

    public function charge(PaymentId $payment, Money $amount, CardToken $card): string
    {
        return $this->guarded(fn(): string => $this->gateway->charge($payment, $amount, $card));
    }

    public function chargeOf(PaymentId $payment): ?ProviderCharge
    {
        return $this->guarded(fn(): ?ProviderCharge => $this->gateway->chargeOf($payment));
    }

    public function refund(PaymentId $payment, string $chargeId, Money $amount): void
    {
        $this->guarded(function () use ($payment, $chargeId, $amount): void {
            $this->gateway->refund($payment, $chargeId, $amount);
        });
    }

    /**
     * @template T
     *
     * @param Closure(): T $call
     *
     * @return T
     */
    private function guarded(Closure $call): mixed
    {
        if (!$this->breaker->allowsCall()) {
            throw GatewayUnavailable::forSeconds(max(1, $this->breaker->secondsUntilRetry()));
        }
        try {
            $result = $call();
        } catch (GatewayFailure $failure) {
            $this->breaker->recordFailure();

            throw $failure;
        } catch (ChargeRefused $refused) {
            // The provider answered: it is up, whatever it thought of the request.
            $this->breaker->recordSuccess();

            throw $refused;
        }
        $this->breaker->recordSuccess();

        return $result;
    }
}
