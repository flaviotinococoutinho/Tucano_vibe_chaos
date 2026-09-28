<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driving\Console;

use Commerce\Payments\Application\Port\Driving\ForReconcilingPayments;
use Commerce\Payments\Application\ReconciledPayment;
use Commerce\Payments\Application\ReconcileResult;
use Commerce\Payments\Domain\GatewayUnavailable;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Tucano\Messaging\Worker\StopSignal;

/**
 * Asks the provider about payments still missing its final word, until SIGTERM.
 * Several copies can run side by side: the claim gives each payment to one of
 * them. This loop is the clock, the actor of UC-PAY-03.
 */
#[AsCommand(name: 'commerce:reconcile-payments', description: 'Ask the payment provider about payments still missing its final word, until SIGTERM')]
final class ReconcilePaymentsWorker extends Command
{
    private const int IDLE_MILLISECONDS = 5_000;

    private const int FAILURE_MILLISECONDS = 2_000;

    public function handle(ForReconcilingPayments $payments, LoggerInterface $logger): int
    {
        $stop = StopSignal::onTermination();
        $logger->info('payment reconciliation started');
        while (!$stop->requested()) {
            try {
                $reconciled = $payments->reconcileNext();
            } catch (GatewayUnavailable $unavailable) {
                // The circuit is open: asking now would only keep it open.
                $logger->warning('Payment provider unavailable, next round in {seconds} s', ['seconds' => $unavailable->retryAfterSeconds]);
                $stop->pause($unavailable->retryAfterSeconds * 1_000);

                continue;
            } catch (Throwable $failure) {
                $logger->error('Payment reconciliation failed: {message}', ['message' => $failure->getMessage(), 'exception' => $failure]);
                $stop->pause(self::FAILURE_MILLISECONDS);

                continue;
            }
            if ($reconciled === null) {
                $stop->pause(self::IDLE_MILLISECONDS);

                continue;
            }
            self::report($logger, $reconciled);
        }
        $logger->info('payment reconciliation stopped');

        return self::SUCCESS;
    }

    private static function report(LoggerInterface $logger, ReconciledPayment $reconciled): void
    {
        $logger->log(
            // A lost charge ends the payment, but it also says the provider is inconsistent: worth a look.
            in_array($reconciled->result, [ReconcileResult::NeedsAttention, ReconcileResult::ChargeLost], true) ? LogLevel::WARNING : LogLevel::INFO,
            'Payment {paymentId} was {status}, the provider has {charge}: {result}',
            [
                'paymentId' => $reconciled->paymentId->toString(),
                'status' => $reconciled->status->value,
                'charge' => $reconciled->charge === null ? 'no charge' : sprintf('%s %s', $reconciled->charge->chargeId, strtolower($reconciled->charge->state->name)),
                'result' => $reconciled->result->value,
            ],
        );
    }
}
