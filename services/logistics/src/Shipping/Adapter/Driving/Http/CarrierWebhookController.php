<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Http;

use DateMalformedStringException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
use Logistics\Shipping\Adapter\CarrierFakeEvents;
use Logistics\Shipping\Application\CarrierJourney;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tucano\Messaging\Webhook\SignatureVerdict;
use Tucano\Messaging\Webhook\WebhookSignature;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Messaging\EventFields;
use Tucano\SharedKernel\Time\Clock;
use ValueError;

/**
 * The events of CarrierFake become steps of the shipment state machine, UC-SHP-04
 * to 08, read by CarrierFakeEvents like the tracking history of UC-SHP-12. 200
 * means "handled, stop sending", also for a duplicate or a code that is not
 * ours. A step the machine refuses answers 409 or 422: the event came before the
 * one it follows, and the carrier sends it again later, when that one may be in.
 */
final readonly class CarrierWebhookController
{
    public function __construct(
        private WebhookSignature $signature,
        private CarrierJourney $journey,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // The raw body, byte for byte: decoding and encoding again would break the HMAC.
        $rawBody = $request->getContent();
        $verdict = $this->signature->verify($rawBody, (string) $request->header('Carrier-Signature', ''), $this->clock->now()->getTimestamp());
        if ($verdict !== SignatureVerdict::Valid) {
            $this->logger->warning('Carrier webhook refused, the signature is {verdict}', ['verdict' => $verdict->value]);

            throw new BadRequestHttpException(sprintf('The Carrier-Signature is %s.', $verdict->value));
        }

        try {
            $body = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
            $fields = new EventFields(is_array($body) ? $body : []);
            $event = CarrierFakeEvents::toCarrierEvent($fields);
        } catch (JsonException|InvalidArgumentException|DateMalformedStringException|ValueError|DomainError $invalid) {
            throw new BadRequestHttpException('The webhook body is not a carrier event: ' . $invalid->getMessage(), $invalid);
        }
        if ($event === null) {
            return new JsonResponse(['result' => 'ignored']);
        }

        $outcome = $event->applyTo($this->journey);
        $this->logger->info('Carrier event {eventId} ({step}) for {trackingCode}: {result}', [
            'eventId' => $event->report->eventId,
            'step' => $event->step->value,
            'trackingCode' => (string) $event->report->trackingCode,
            'result' => $outcome->value,
        ]);

        return new JsonResponse(['result' => $outcome->value]);
    }
}
