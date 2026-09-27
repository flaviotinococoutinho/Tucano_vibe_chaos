<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Commerce\Payments\Adapter\Driven\PayFakeGateway;
use Commerce\Payments\Domain\CardToken;
use Commerce\Payments\Domain\ChargeRefused;
use Commerce\Payments\Domain\GatewayFailure;
use Commerce\Payments\Domain\PaymentId;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;
use Throwable;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

final class PayFakeGatewayTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    #[Test]
    public function the_payment_id_travels_as_the_idempotency_key_and_the_reference(): void
    {
        $payment = PaymentId::generate();

        $chargeId = $this->gateway(new Response(201, [], '{"id":"ch_01ABC","status":"processing"}'))
            ->charge($payment, Money::of(18990, Currency::brl()), CardToken::of('tok_visa'));

        self::assertSame('ch_01ABC', $chargeId);
        $request = $this->sent[0];
        self::assertSame(['POST', '/payfake/v1/charges', $payment->toString()], [$request->getMethod(), $request->getUri()->getPath(), $request->getHeaderLine('Idempotency-Key')]);
        self::assertSame(
            ['amount' => ['value' => 18990, 'currency' => 'BRL'], 'cardToken' => 'tok_visa', 'reference' => $payment->toString()],
            json_decode((string) $request->getBody(), true),
        );
    }

    #[Test]
    public function a_provider_error_or_no_answer_is_a_failure_with_an_unknown_outcome(): void
    {
        foreach ([new Response(503), new ConnectException('Connection refused', new Request('POST', '/'))] as $answer) {
            try {
                $this->gateway($answer)->charge(PaymentId::generate(), Money::of(100, Currency::brl()), CardToken::of('tok_visa'));
                self::fail('A failure was expected.');
            } catch (GatewayFailure) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_refused_request_keeps_the_reason_of_the_provider(): void
    {
        $this->expectExceptionObject(ChargeRefused::because('Idempotency-Key k-1 was used with another body.'));

        $this->gateway(new Response(422, [], '{"detail":"Idempotency-Key k-1 was used with another body."}'))
            ->charge(PaymentId::generate(), Money::of(100, Currency::brl()), CardToken::of('tok_visa'));
    }

    #[Test]
    public function chaos_latency_past_the_timeout_fails_without_calling_the_provider(): void
    {
        try {
            $this->gateway(new Response(201, [], '{"id":"ch_1"}'), latencyMs: 80, timeoutMs: 30)
                ->charge(PaymentId::generate(), Money::of(100, Currency::brl()), CardToken::of('tok_visa'));
            self::fail('A timeout was expected.');
        } catch (GatewayFailure $failure) {
            self::assertStringContainsString('chaos latency of 80 ms', $failure->getMessage());
        }

        self::assertSame([], $this->sent);
    }

    private function gateway(Response|Throwable $answer, int $latencyMs = 0, int $timeoutMs = 2_000): PayFakeGateway
    {
        $stack = HandlerStack::create(new MockHandler([$answer]));
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->sent[] = $request;

            return $request;
        }));

        return new PayFakeGateway(
            new Client(['handler' => $stack, 'base_uri' => 'http://payfake.test']),
            new InMemoryFlags(['chaos.commerce.payment-gateway-latency-ms' => $latencyMs]),
            $timeoutMs,
        );
    }
}
