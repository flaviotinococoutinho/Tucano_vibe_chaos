<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\UseCase;

use Logistics\Shipping\Application\Port\Driven\ForFindingStalledJourneys;
use Logistics\Shipping\Application\Port\Driven\ForRaisingAlerts;
use Logistics\Shipping\Application\Port\Driving\ForWatchingStalledJourneys;
use Logistics\Shipping\Application\StalledJourneys;
use Tucano\SharedKernel\Documentation\UseCase;
use Tucano\SharedKernel\Time\Clock;

/**
 * UC-SHP-13: the async journey heals most stops on its own (webhooks, retries,
 * the reconciliation of UC-SHP-12). What is left after all that is not a job
 * for a retry, it is a question for a person: an analytical read finds the
 * shipments with a carrier and no step for too long, and an alert says which
 * ones and why.
 */
#[UseCase('UC-SHP-13')]
final readonly class WatchStalledJourneys implements ForWatchingStalledJourneys
{
    public function __construct(
        private ForFindingStalledJourneys $journeys,
        private ForRaisingAlerts $alerts,
        private Clock $clock,
        private int $stalledAfterSeconds,
        private int $alertLimit,
    ) {}

    public function watch(): StalledJourneys
    {
        $stalled = $this->journeys->quietSince($this->clock->now()->modify(sprintf('-%d seconds', $this->stalledAfterSeconds)), $this->alertLimit);
        if (!$stalled->isEmpty()) {
            $this->alerts->raise($stalled->toAlert());
        }

        return $stalled;
    }
}
