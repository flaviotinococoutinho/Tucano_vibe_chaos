<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shipping\Application\Port\Driving\ForWatchingStalledJourneys;
use Logistics\Shipping\Application\StalledJourney;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Tucano\Messaging\Worker\StopSignal;

/**
 * UC-SHP-13 on a schedule: a round every JOURNEYS_STALLED_WATCH_EVERY_SECONDS
 * until SIGTERM. With --once it runs a single round, alert included, and
 * prints what it found; that is also the shape for a cron or a CronJob.
 */
#[AsCommand(name: 'logistics:watch-stalled-journeys', description: 'Find shipments stalled with their carriers and raise an alert')]
final class WatchStalledJourneysWorker extends Command
{
    /** @var string */
    protected $signature = 'logistics:watch-stalled-journeys {--once : run one round, print what it found and stop}';

    public function handle(ForWatchingStalledJourneys $watch, LoggerInterface $logger): int
    {
        if ($this->option('once') === true) {
            $stalled = $watch->watch();
            $this->line(sprintf('%d stalled with no step since %s', $stalled->total, $stalled->quietSince->format('Y-m-d H:i T')));
            $this->table(['tracking code', 'status', 'carrier', 'last step', 'why'], array_map(static fn(StalledJourney $journey): array => [
                $journey->trackingCode, $journey->status, $journey->carrier, $journey->lastStepAt->format('Y-m-d H:i T'), $journey->reason(),
            ], $stalled->shown));

            return self::SUCCESS;
        }

        $every = (int) config('journeys.stalled.watch_every_seconds') * 1_000;
        $afterFailure = (int) config('journeys.stalled.failure_pause_ms');
        $stop = StopSignal::onTermination();
        $logger->info('stalled journey watch started');
        while (!$stop->requested()) {
            try {
                $stalled = $watch->watch();
                $logger->info('Stalled journey watch: {total} stalled', ['total' => $stalled->total]);
                $stop->pause($every);
            } catch (Throwable $failure) {
                $logger->error('Stalled journey watch failed: {message}', ['message' => $failure->getMessage(), 'exception' => $failure]);
                $stop->pause($afterFailure);
            }
        }
        $logger->info('stalled journey watch stopped');

        return self::SUCCESS;
    }
}
