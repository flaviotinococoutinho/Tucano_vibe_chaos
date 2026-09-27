<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Http;

use Closure;
use DateMalformedStringException;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Application\Port\Driving\ForDispatchingDeliveries;
use Logistics\Shipping\Application\Port\Driving\ForRecordingDeliveryOutcomes;
use Logistics\Shipping\Application\Port\Driving\ForRecordingHubScans;
use Logistics\Shipping\Application\Port\Driving\ForRecordingPickups;
use Logistics\Shipping\Application\Port\Driving\ForReturningToSender;
use Logistics\Shipping\Application\ProgressOutcome;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\Hub;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
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
 * to 08; the carrier vocabulary (parcel, visit) stops here. 200 means "handled,
 * stop sending", also for a duplicate or a code that is not ours. A step the
 * machine refuses answers 409 or 422: the event came before the one it follows,
 * and the carrier sends it again later, when that one may be in.
 */
final readonly class CarrierWebhookController
{
    public function __construct(
        private WebhookSignature $signature,
        private ForRecordingPickups $pickups,
        private ForRecordingHubScans $hubScans,
        private ForDispatchingDeliveries $dispatches,
        private ForRecordingDeliveryOutcomes $visits,
        private ForReturningToSender $returns,
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
            $event = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
            $fields = new EventFields(is_array($event) ? $event : []);
            $type = $fields->text('type');
            $data = $fields->object('data');
            $report = new CarrierReport($fields->text('id'), TrackingCode::fromString($data->text('trackingCode')), new DateTimeImmutable($fields->text('createdAt')));
            $step = $this->stepOf($type, $data);
        } catch (JsonException|InvalidArgumentException|DateMalformedStringException|ValueError|DomainError $invalid) {
            throw new BadRequestHttpException('The webhook body is not a carrier event: ' . $invalid->getMessage(), $invalid);
        }
        if ($step === null) {
            return new JsonResponse(['result' => 'ignored']);
        }

        $outcome = $step($report);
        $this->logger->info('Carrier event {eventId} ({type}) for {trackingCode}: {result}', [
            'eventId' => $report->eventId,
            'type' => $type,
            'trackingCode' => (string) $report->trackingCode,
            'result' => $outcome->value,
        ]);

        return new JsonResponse(['result' => $outcome->value]);
    }

    /**
     * The use case that takes the event, with what the event carries already read
     * (a missing hub or receiver fails here, as a bad request); null for an event
     * type Shipping does not know, which is left alone (tolerant reader).
     *
     * @return (Closure(CarrierReport): ProgressOutcome)|null
     */
    private function stepOf(string $type, EventFields $data): ?Closure
    {
        return match ($type) {
            'parcel.picked_up' => $this->pickups->recordPickup(...),
            'parcel.hub_scanned' => self::with($this->hubScans->recordHubScan(...), Hub::named($data->text('hub'))),
            'parcel.out_for_delivery' => $this->dispatches->dispatch(...),
            'parcel.delivered' => self::with($this->visits->recordDelivery(...), ProofOfDelivery::of($data->text('receiverName'), $data->text('receiverDocument'))),
            'parcel.delivery_failed' => self::with($this->visits->recordFailedVisit(...), DeliveryFailure::from($data->text('reason'))),
            'parcel.returning' => $this->returns->startReturn(...),
            'parcel.returned' => $this->returns->completeReturn(...),
            default => null,
        };
    }

    /**
     * @template T of object
     *
     * @param Closure(CarrierReport, T): ProgressOutcome $useCase
     * @param T                                         $evidence
     *
     * @return Closure(CarrierReport): ProgressOutcome
     */
    private static function with(Closure $useCase, object $evidence): Closure
    {
        return static fn(CarrierReport $report): ProgressOutcome => $useCase($report, $evidence);
    }
}
