<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driven;

use Commerce\Payments\Application\Port\Driven\ForChargingCards;
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
 * the same payment can never create a second charge.
 */
final readonly class PayFakeGateway implements ForChargingCards
{
    private const string LATENCY_FLAG = 'chaos.commerce.payment-gateway-latency-ms';

    public function __construct(
        private ClientInterface $http,
        private FeatureFlags $flags,
        private int $timeoutMilliseconds,
    ) {}

    public function ensureAvailable(): void {}

    public function charge(PaymentId $payment, Money $amount, CardToken $card): string
    {
        $budget = $this->afterInjectedLatency();
        try {
            $response = $this->http->request('POST', '/payfake/v1/charges', [
                'headers' => [
                    'Idempotency-Key' => $payment->toString(),
                    'X-Correlation-Id' => (string) Context::get('correlation_id', ''),
                ],
                'json' => [
                    'amount' => ['value' => $amount->cents(), 'currency' => $amount->currency()->code()],
                    'cardToken' => $card->value,
                    'reference' => $payment->toString(),
                ],
                'timeout' => $budget / 1_000,
                'connect_timeout' => min(0.5, $budget / 1_000),
                'http_errors' => false,
            ]);
        } catch (GuzzleException $failure) {
            throw GatewayFailure::because($failure->getMessage(), $failure);
        }

        $status = $response->getStatusCode();
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true) ?? [];
        if ($status >= 500) {
            throw GatewayFailure::because(sprintf('HTTP %d', $status));
        }
        if ($status >= 400) {
            throw ChargeRefused::because(is_string($body['detail'] ?? null) ? $body['detail'] : sprintf('HTTP %d', $status));
        }
        $chargeId = $body['id'] ?? null;

        return is_string($chargeId) && $chargeId !== '' ? $chargeId : throw GatewayFailure::because('the answer has no charge id');
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
