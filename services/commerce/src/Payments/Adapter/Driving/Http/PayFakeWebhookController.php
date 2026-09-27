<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driving\Http;

use Commerce\Payments\Application\Port\Driving\ForSettlingPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tucano\Messaging\Webhook\SignatureVerdict;
use Tucano\Messaging\Webhook\WebhookSignature;
use Tucano\SharedKernel\Time\Clock;

/**
 * 200 means "handled, stop sending": also for a duplicate, an unknown payment or
 * an event type nobody here reads. Anything the provider can fix by sending again
 * (a database hiccup, say) is a 5xx, and it sends again.
 */
final readonly class PayFakeWebhookController
{
    public function __construct(
        private ForSettlingPayments $payments,
        private WebhookSignature $signature,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // The raw body, byte for byte: decoding and encoding again would break the HMAC.
        $rawBody = $request->getContent();
        $verdict = $this->signature->verify($rawBody, (string) $request->header('PayFake-Signature', ''), $this->clock->now()->getTimestamp());
        if ($verdict !== SignatureVerdict::Valid) {
            $this->logger->warning('PayFake webhook refused, the signature is {verdict}', ['verdict' => $verdict->value]);

            throw new BadRequestHttpException(sprintf('The PayFake-Signature is %s.', $verdict->value));
        }

        try {
            $event = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
            $outcome = PayFakeEvents::outcomeOf(is_array($event) ? $event : []);
        } catch (JsonException|InvalidArgumentException $invalid) {
            throw new BadRequestHttpException('The webhook body is not a PayFake event: ' . $invalid->getMessage(), $invalid);
        }
        if ($outcome === null) {
            return new JsonResponse(['result' => 'ignored']);
        }

        $result = $this->payments->settle($outcome);
        $this->logger->info('PayFake event {event} for payment {payment}: {result}', [
            'event' => $outcome->eventId,
            'payment' => $outcome->paymentId->toString(),
            'result' => $result->value,
        ]);

        return new JsonResponse(['result' => $result->value]);
    }
}
