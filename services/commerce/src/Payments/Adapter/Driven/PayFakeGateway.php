<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driven;

use Commerce\Payments\Application\ChargeState;
use Commerce\Payments\Application\Port\Driven\ForChargingCards;
use Commerce\Payments\Application\ProviderCharge;
use Commerce\Payments\Domain\CardToken;
use Commerce\Payments\Domain\ChargeRefused;
use Commerce\Payments\Domain\GatewayFailure;
use Commerce\Payments\Domain\PaymentId;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Context;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\SharedKernel\Money\Money;

/**
 * The anticorruption layer with PayFake: its vocabulary (charge, cardToken,
 * value) stops here. The payment id goes as the Idempotency-Key, so resending
 * the same payment can never create a second charge, nor a second refund.
 */
final readonly class PayFakeGateway implements ForChargingCards
{
    private const string LATENCY_FLAG = 'chaos.commerce.payment-gateway-latency-ms';

    public function __construct(
        private ClientInterface $http,
        private FeatureFlags $flags,
        private int $timeoutMilliseconds,
        private int $connectTimeoutMilliseconds,
    ) {}

    public function ensureAvailable(): void {}

    public function charge(PaymentId $payment, Money $amount, CardToken $card): string
    {
        $charge = $this->send('POST', '/payfake/v1/charges', [
            'headers' => ['Idempotency-Key' => $payment->toString()],
            'json' => [
                'amount' => self::money($amount),
                'cardToken' => $card->reveal(),
                'reference' => $payment->toString(),
            ],
        ]);
        $chargeId = $charge['id'] ?? null;

        return is_string($chargeId) && $chargeId !== '' ? $chargeId : throw GatewayFailure::because('the answer has no charge id');
    }

    public function chargeOf(PaymentId $payment): ?ProviderCharge
    {
        $found = $this->send('GET', '/payfake/v1/charges', ['query' => ['reference' => $payment->toString()]]);
        $charges = $found['data'] ?? null;
        if (!is_array($charges)) {
            throw GatewayFailure::because('the answer has no list of charges');
        }
        $charge = $charges[0] ?? null;

        return is_array($charge) ? self::providerCharge($charge) : null;
    }

    public function refund(PaymentId $payment, string $chargeId, Money $amount): void
    {
        $this->send('POST', sprintf('/payfake/v1/charges/%s/refunds', rawurlencode($chargeId)), [
            'headers' => ['Idempotency-Key' => $payment->toString()],
            'json' => ['amount' => self::money($amount)],
        ]);
    }

    /**
     * One call to PayFake: a 5xx or no answer is a failure with an unknown outcome, and any
     * other 4xx is a refusal of the request itself.
     *
     * @param array{headers?: array<string, string>, json?: array<string, mixed>, query?: array<string, string>} $options
     *
     * @return array<mixed> the decoded body
     */
    private function send(string $method, string $uri, array $options): array
    {
        $budget = $this->afterInjectedLatency();
        $options['headers'] = [...$options['headers'] ?? [], 'X-Correlation-Id' => (string) Context::get('correlation_id', '')];
        try {
            $response = $this->http->request($method, $uri, [
                ...$options,
                'timeout' => $budget / 1_000,
                'connect_timeout' => min($this->connectTimeoutMilliseconds, $budget) / 1_000,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $failure) {
            throw GatewayFailure::because($failure->getMessage(), $failure);
        }

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : [];
        if ($status >= 500) {
            throw GatewayFailure::because(sprintf('HTTP %d', $status));
        }
        if ($status >= 400) {
            throw ChargeRefused::because(is_string($body['detail'] ?? null) ? $body['detail'] : sprintf('HTTP %d', $status));
        }

        return $body;
    }

    /** @return array{value: int, currency: string} */
    private static function money(Money $amount): array
    {
        return ['value' => $amount->cents(), 'currency' => $amount->currency()->code()];
    }

    /**
     * PayFake's charge, one of four states, in Payments' words. A refunded charge carries its
     * refund, and only a refund that succeeded means the money is back.
     *
     * @param array<mixed> $charge
     */
    private static function providerCharge(array $charge): ProviderCharge
    {
        $id = $charge['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw GatewayFailure::because('a charge came without its id');
        }
        $refund = $charge['refund'] ?? null;
        $state = match ($charge['status'] ?? null) {
            'processing' => ChargeState::Processing,
            'succeeded' => ChargeState::Succeeded,
            'failed' => ChargeState::Failed,
            'refunded' => is_array($refund) && ($refund['status'] ?? null) === 'succeeded' ? ChargeState::Refunded : ChargeState::Refunding,
            default => throw GatewayFailure::because(sprintf('charge %s has a status Payments does not know', $id)),
        };
        $failureCode = $charge['failureCode'] ?? null;

        return new ProviderCharge($id, $state, is_string($failureCode) ? $failureCode : null);
    }

    /**
     * The chaos flag makes the provider look slow. The delay counts against the
     * same timeout a real slow provider would hit, and eats into it.
     *
     * @return int milliseconds left for the call itself
     */
    private function afterInjectedLatency(): int
    {
        $latency = max(0, $this->flags->integer(self::LATENCY_FLAG, 0));
        if ($latency >= $this->timeoutMilliseconds) {
            usleep($this->timeoutMilliseconds * 1_000);

            throw GatewayFailure::because(sprintf('timed out after %d ms (chaos latency of %d ms)', $this->timeoutMilliseconds, $latency));
        }
        usleep($latency * 1_000);

        return $this->timeoutMilliseconds - $latency;
    }
}
