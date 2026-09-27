<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Logistics\Shipping\Application\Port\Driving\ForGeneratingLabels;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Psr\Log\LoggerInterface;
use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/**
 * One message of the label-jobs queue, the driving side of UC-SHP-03. The job
 * and the queue agree on three tries: Laravel tries three times and records a
 * failure in failed_jobs, and a worker that dies mid-job records nothing, so
 * SQS moves the message to label-jobs-dlq on its third receive.
 */
final class GenerateLabelJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds before the second and the third try */
    public array $backoff = [5, 20];

    /** Below the visibility timeout of the queue (60 s), so SQS never hands the message to a second worker mid-job. */
    public int $timeout = 30;

    public function __construct(public readonly string $shipmentId) {}

    /** Laravel calls this once the job is in failed_jobs, where `php artisan queue:retry` finds it. */
    public function failed(Throwable $reason): void
    {
        Log::error('Label of shipment {shipmentId} gave up and waits in failed_jobs: {reason}', [
            'shipmentId' => $this->shipmentId,
            'reason' => $reason->getMessage(),
        ]);
    }

    public function handle(ForGeneratingLabels $labels, LoggerInterface $logger): void
    {
        try {
            $outcome = $labels->generate(ShipmentId::fromString($this->shipmentId));
        } catch (DomainError $error) {
            if ($error->category() === ErrorCategory::Unavailable) {
                // Domain errors stay out of the error log of the service; a job that will be
                // tried again is worth a warning, so the retries can be followed.
                $logger->warning('Label of shipment {shipmentId} failed on attempt {attempt} of {tries}: {reason}', [
                    'shipmentId' => $this->shipmentId,
                    'attempt' => $this->attempts(),
                    'tries' => $this->tries,
                    'reason' => $error->getMessage(),
                ]);

                throw $error;
            }
            // Trying again would fail the same way: straight to failed_jobs.
            $this->fail($error);

            return;
        }
        $logger->info('Label of shipment {shipmentId}: {outcome}', [
            'shipmentId' => $this->shipmentId,
            'outcome' => $outcome->value,
            'attempt' => $this->attempts(),
        ]);
    }
}
