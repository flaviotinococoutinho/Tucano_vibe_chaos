<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shipping\Application\JourneyResult;
use Logistics\Shipping\Application\Port\Driving\ForReconcilingJourneys;
use Logistics\Shipping\Application\ReconciledJourney;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Tucano\Messaging\Worker\StopSignal;

/** UC-SHP-12 in a loop: one quiet shipment at a time, a pause when none is due, until SIGTERM. */
#[AsCommand(name: 'logistics:reconcile-journeys', description: 'Catch shipments up with the tracking history of their carriers until SIGTERM')]
final class ReconcileJourneysWorker extends Command
{
    public function handle(ForReconcilingJourneys $journeys, LoggerInterface $logger): int
    {
        $idle = (int) config('journeys.reconciliation.idle_pause_ms');
        $afterFailure = (int) config('journeys.reconciliation.failure_pause_ms');
        $stop = StopSignal::onTermination();
        $logger->info('journey reconciliation started');
        while (!$stop->requested()) {
            try {
                $reconciled = $journeys->reconcileNext();
            } catch (Throwable $failure) {
                $logger->error('Journey reconciliation failed: {message}', ['message' => $failure->getMessage(), 'exception' => $failure]);
                $stop->pause($afterFailure);

                continue;
            }
            if ($reconciled === null) {
                $stop->pause($idle);

                continue;
            }
            self::report($logger, $reconciled);
        }
        $logger->info('journey reconciliation stopped');

        return self::SUCCESS;
    }

    private static function report(LoggerInterface $logger, ReconciledJourney $reconciled): void
    {
        $level = match ($reconciled->result) {
            JourneyResult::Stopped => LogLevel::WARNING,
            JourneyResult::CarrierUnreachable, JourneyResult::UnknownToCarrier => LogLevel::NOTICE,
            JourneyResult::CaughtUp, JourneyResult::UpToDate => LogLevel::INFO,
        };
        $logger->log($level, 'Shipment {trackingCode} against its carrier: {result}, {applied} steps applied{refusal}', [
            'trackingCode' => (string) $reconciled->trackingCode,
            'result' => $reconciled->result->value,
            'applied' => $reconciled->applied,
            'refusal' => $reconciled->refusal === null ? '' : ' before "' . $reconciled->refusal . '"',
        ]);
    }
}
