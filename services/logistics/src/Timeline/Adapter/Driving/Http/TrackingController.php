<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driving\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Logistics\Timeline\Application\Port\Driving\ForTrackingShipments;
use Logistics\Timeline\Domain\TrackingPagesUnavailable;
use Logistics\Timeline\Domain\TrackingView;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The public page, platform-wide or through a store: 404 as problem details for a code it
 * does not know (yet), or that belongs to another store, and 503 with Retry-After when the
 * pages are out of reach.
 */
final readonly class TrackingController
{
    public function __construct(private ForTrackingShipments $tracking, private LoggerInterface $logger) {}

    /** GET /v1/tracking/{trackingCode}: the page of any store, with the store it belongs to. */
    public function platformWide(string $trackingCode): JsonResponse
    {
        return $this->answer(fn(): TrackingView => $this->tracking->track($trackingCode));
    }

    /** GET /v1/stores/{store}/tracking/{trackingCode}: the same page, only for its own store (ADR 0031). */
    public function inStore(string $store, string $trackingCode): JsonResponse
    {
        return $this->answer(fn(): TrackingView => $this->tracking->trackInStore($store, $trackingCode));
    }

    /** @param Closure(): TrackingView $read */
    private function answer(Closure $read): JsonResponse
    {
        try {
            return new JsonResponse($read()->toArray());
        } catch (TrackingPagesUnavailable $unavailable) {
            // An HTTP exception is an answer, not an incident, so nothing else would log the outage.
            $this->logger->warning('Tracking pages out of reach: {cause}', ['cause' => $unavailable->getPrevious()?->getMessage()]);

            throw new ServiceUnavailableHttpException($unavailable->retryAfterSeconds, $unavailable->getMessage(), $unavailable);
        }
    }
}
