<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driving\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Timeline\Application\Port\Driving\ForTrackingShipments;
use Logistics\Timeline\Domain\TrackingPagesUnavailable;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * GET /v1/tracking/{trackingCode}: the public page, 404 as problem details for a code it does
 * not know (yet), and 503 with Retry-After when the pages are out of reach.
 */
final readonly class TrackingController
{
    public function __construct(private ForTrackingShipments $tracking, private LoggerInterface $logger) {}

    public function __invoke(string $trackingCode): JsonResponse
    {
        try {
            return new JsonResponse($this->tracking->track($trackingCode)->toArray());
        } catch (TrackingPagesUnavailable $unavailable) {
            // An HTTP exception is an answer, not an incident, so nothing else would log the outage.
            $this->logger->warning('Tracking pages out of reach: {cause}', ['cause' => $unavailable->getPrevious()?->getMessage()]);

            throw new ServiceUnavailableHttpException($unavailable->retryAfterSeconds, $unavailable->getMessage(), $unavailable);
        }
    }
}
