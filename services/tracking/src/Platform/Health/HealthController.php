<?php

declare(strict_types=1);

namespace Tracking\Platform\Health;

use Psr\Log\LoggerInterface;
use Tracking\Platform\Http\Response;

final readonly class HealthController
{
    public function __construct(
        private Readiness $readiness,
        private LoggerInterface $logger,
    ) {}

    /** Liveness: the process answers. Orchestrators restart the container when this fails. */
    public function live(): Response
    {
        return Response::json(['status' => 'up']);
    }

    /** Readiness: dependencies answer too. Load balancers stop sending traffic when this fails. */
    public function ready(): Response
    {
        $report = $this->readiness->probe();
        if (!$report->isHealthy()) {
            // Load balancers only look at the status code, so the reason has to reach the log.
            $this->logger->warning('Not ready', ['checks' => $report->toArray()['checks']]);
        }

        return Response::json($report->toArray(), $report->isHealthy() ? 200 : 503);
    }
}
