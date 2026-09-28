<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driving\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Timeline\Application\Port\Driving\ForTrackingShipments;

/** GET /v1/tracking/{trackingCode}: the public page, 404 as problem details for a code it does not know (yet). */
final readonly class TrackingController
{
    public function __construct(private ForTrackingShipments $tracking) {}

    public function __invoke(string $trackingCode): JsonResponse
    {
        return new JsonResponse($this->tracking->track($trackingCode)->toArray());
    }
}
