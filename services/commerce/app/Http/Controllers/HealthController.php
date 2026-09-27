<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Health\Readiness;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class HealthController
{
    /** Liveness: the process answers. Orchestrators restart the container when this fails. */
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'up']);
    }

    /** Readiness: dependencies answer too. Load balancers stop sending traffic when this fails. */
    public function ready(Readiness $readiness): JsonResponse
    {
        $report = $readiness->probe();

        return new JsonResponse($report->toArray(), $report->isHealthy() ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
