<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Commerce\Payments\Adapter\Driven\PayFakeGateway;
use Commerce\Payments\Application\ChargeState;
use Commerce\Payments\Application\ProviderCharge;
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
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    public function a_charge_is_found_by_the_payment_it_was_made_for(): void
    {
        $payment = PaymentId::generate();

        $charge = $this->gateway(self::found(self::payfakeCharge('processing')))->chargeOf($payment);

        self::assertEquals(new ProviderCharge('ch_01ABC', ChargeState::Processing), $charge);
        $request = $this->sent[0];
        self::assertSame(
            ['GET', '/payfake/v1/charges', 'reference=' . $payment->toString()],
            [$request->getMethod(), $request->getUri()->getPath(), $request->getUri()->getQuery()],
        );
    }

    /** @param array<string, mixed> $payfakeCharge */
    #[Test]
    #[DataProvider('chargesInBothVocabularies')]
    public function each_state_of_a_payfake_charge_has_its_name_in_payments(array $payfakeCharge, ProviderCharge $expected): void
    {
        self::assertEquals($expected, $this->gateway(self::found($payfakeCharge))->chargeOf(PaymentId::generate()));
    }

    /** @return iterable<string, array{array<string, mixed>, ProviderCharge}> */
    public static function chargesInBothVocabularies(): iterable
    {
        yield 'succeeded' => [self::payfakeCharge('succeeded'), new ProviderCharge('ch_01ABC', ChargeState::Succeeded)];
        yield 'failed, with its code' => [
            self::payfakeCharge('failed', ['failureCode' => 'insufficient_funds']),
            new ProviderCharge('ch_01ABC', ChargeState::Failed, 'insufficient_funds'),
        ];
        yield 'refunded, the refund still processing' => [
            self::payfakeCharge('refunded', ['refund' => ['id' => 're_01ABC', 'status' => 'processing']]),
            new ProviderCharge('ch_01ABC', ChargeState::Refunding),
        ];
        yield 'refunded, the money back' => [
            self::payfakeCharge('refunded', ['refund' => ['id' => 're_01ABC', 'status' => 'succeeded']]),
            new ProviderCharge('ch_01ABC', ChargeState::Refunded),
        ];
    }

    #[Test]
    public function a_payment_payfake_never_charged_has_no_charge(): void
    {
        self::assertNull($this->gateway(new Response(200, [], '{"data":[]}'))->chargeOf(PaymentId::generate()));
    }

    #[Test]
    public function a_charge_in_a_state_payments_does_not_know_is_a_failure(): void
    {
        $this->expectException(GatewayFailure::class);

        $this->gateway(self::found(self::payfakeCharge('disputed')))->chargeOf(PaymentId::generate());
    }

    #[Test]
    public function a_refund_goes_to_the_charge_for_the_whole_amount_keyed_by_the_payment(): void
    {
        $payment = PaymentId::generate();

        $this->gateway(new Response(201, [], '{"id":"re_01ABC","status":"processing"}'))
            ->refund($payment, 'ch_01ABC', Money::of(18990, Currency::brl()));

        $request = $this->sent[0];
        self::assertSame(
            ['POST', '/payfake/v1/charges/ch_01ABC/refunds', $payment->toString()],
            [$request->getMethod(), $request->getUri()->getPath(), $request->getHeaderLine('Idempotency-Key')],
        );
        self::assertSame(['amount' => ['value' => 18990, 'currency' => 'BRL']], json_decode((string) $request->getBody(), true));
    }

    #[Test]
    public function a_charge_payfake_cannot_refund_is_a_refusal(): void
    {
        $this->expectExceptionObject(ChargeRefused::because('Charge ch_01ABC is processing and cannot be refunded.'));

        $this->gateway(new Response(409, [], '{"detail":"Charge ch_01ABC is processing and cannot be refunded."}'))
            ->refund(PaymentId::generate(), 'ch_01ABC', Money::of(18990, Currency::brl()));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function payfakeCharge(string $status, array $extra = []): array
    {
        return [
            'id' => 'ch_01ABC',
            'status' => $status,
            'amount' => ['value' => 18990, 'currency' => 'BRL'],
            'reference' => '01926f3a-8c1e-7b2d-9f4a-3c5e6d7f8a9b',
            'createdAt' => '2026-09-27T12:00:00.000Z',
            ...$extra,
        ];
    }

    /** @param array<string, mixed> $charge */
    private static function found(array $charge): Response
    {
        return new Response(200, [], json_encode(['data' => [$charge]], JSON_THROW_ON_ERROR));
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
